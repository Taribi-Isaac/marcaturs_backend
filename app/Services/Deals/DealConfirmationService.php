<?php

namespace App\Services\Deals;

use App\Enums\CommissionTrigger;
use App\Enums\CommissionType;
use App\Enums\DealEventType;
use App\Enums\DealStatus;
use App\Enums\PaymentEvidenceStatus;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\PaymentEvidence;
use App\Models\User;
use App\Services\Notifications\CommissionNotificationDispatcher;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use App\Support\Money\DecimalMoney;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class DealConfirmationService
{
    public function __construct(
        private readonly CommissionLiabilityService $liabilities,
        private readonly CommissionNotificationDispatcher $commissionNotifications,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function confirm(User $business, Deal $deal, array $attributes): Deal
    {
        $this->assertBusinessOwner($business, $deal);

        $createdCommission = null;

        $sealed = DB::transaction(function () use ($business, $deal, $attributes, &$createdCommission): Deal {
            $locked = Deal::query()->whereKey($deal->id)->lockForUpdate()->firstOrFail();

            if ($locked->status->isSealed()) {
                return $this->withShowRelations($locked);
            }

            $this->assertConfirmable($locked);

            $evidenceIds = PaymentEvidence::query()
                ->where('deal_id', $locked->id)
                ->where('status', PaymentEvidenceStatus::Submitted)
                ->orderBy('id')
                ->pluck('id')
                ->all();

            if ($evidenceIds === []) {
                throw new HttpResponseException(ApiResponse::error(
                    ApiErrorCode::BUSINESS_VALIDATION,
                    'At least one submitted payment evidence record is required before confirmation.',
                    422,
                ));
            }

            if ($locked->commission_trigger !== CommissionTrigger::PaymentConfirmation) {
                throw new HttpResponseException(ApiResponse::error(
                    ApiErrorCode::BUSINESS_VALIDATION,
                    'Ordinary payment confirmation is only available when the commission trigger is payment confirmation.',
                    422,
                ));
            }

            $snapshotCommissionAmount = $locked->commission_amount;
            $confirmedPaymentAmount = null;
            $commissionAmount = $snapshotCommissionAmount;

            if ($locked->commission_type === CommissionType::Percentage) {
                $rawAmount = $attributes['confirmed_payment_amount'] ?? null;

                if ($rawAmount === null || $rawAmount === '') {
                    throw new HttpResponseException(ApiResponse::error(
                        ApiErrorCode::VALIDATION_ERROR,
                        'The given data was invalid.',
                        400,
                        ['confirmed_payment_amount' => ['The confirmed payment amount is required for percentage commission Deals.']],
                    ));
                }

                if ($locked->commission_rate === null) {
                    throw new HttpResponseException(ApiResponse::error(
                        ApiErrorCode::BUSINESS_VALIDATION,
                        'The Deal is missing a commission rate required to calculate commission.',
                        422,
                    ));
                }

                $confirmedPaymentAmount = DecimalMoney::normalize((string) $rawAmount);
                $commissionAmount = DecimalMoney::percentageOf($confirmedPaymentAmount, (string) $locked->commission_rate);
            }

            $previous = $locked->status;
            $locked->confirmed_payment_amount = $confirmedPaymentAmount;
            $locked->commission_amount = $commissionAmount;
            $locked->status = DealStatus::Sealed;
            $locked->confirmed_at = now();
            $locked->save();

            $createdCommission = $this->liabilities->createForSealedDeal($locked);

            $metadata = [
                'payment_evidence_ids' => $evidenceIds,
                'commission_id' => $createdCommission->id,
                'confirmed_payment_amount' => $confirmedPaymentAmount,
                'commission_type' => $locked->commission_type->value,
                'commission_rate' => $locked->commission_rate,
                'commission_amount' => $commissionAmount,
                'snapshot_commission_amount' => $snapshotCommissionAmount,
                'confirmed_at' => $locked->confirmed_at?->toIso8601String(),
                'commission_due_at' => $createdCommission->due_at?->toIso8601String(),
            ];

            $this->writeEvent($locked, $business, DealEventType::PaymentConfirmed, $previous, $metadata);
            $this->writeEvent($locked, $business, DealEventType::DealSealed, $previous, $metadata);
            $this->writeEvent($locked, $business, DealEventType::CommissionDue, $previous, $metadata);

            return $this->withShowRelations($locked);
        });

        if ($createdCommission !== null) {
            $this->commissionNotifications->notifyDue($createdCommission);
        }

        return $sealed;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function reject(User $business, Deal $deal, PaymentEvidence $evidence, array $attributes): PaymentEvidence
    {
        $this->assertBusinessOwner($business, $deal);

        if ($evidence->deal_id !== $deal->id) {
            throw new ModelNotFoundException;
        }

        return DB::transaction(function () use ($business, $deal, $evidence, $attributes): PaymentEvidence {
            $lockedDeal = Deal::query()->whereKey($deal->id)->lockForUpdate()->firstOrFail();
            $lockedEvidence = PaymentEvidence::query()->whereKey($evidence->id)->lockForUpdate()->firstOrFail();

            if ($lockedEvidence->deal_id !== $lockedDeal->id) {
                throw new ModelNotFoundException;
            }

            if ($lockedDeal->status->isSealed()) {
                throw new HttpResponseException(ApiResponse::error(
                    ApiErrorCode::CONFLICT,
                    'A sealed Deal cannot be rejected.',
                    409,
                ));
            }

            if (! $lockedDeal->status->allowsRejection()) {
                throw new HttpResponseException(ApiResponse::error(
                    ApiErrorCode::CONFLICT,
                    'This Deal cannot be rejected in its current state.',
                    409,
                ));
            }

            if ($lockedEvidence->status === PaymentEvidenceStatus::Rejected) {
                return $lockedEvidence->loadMissing('ambassador');
            }

            $reason = trim((string) $attributes['reason']);
            $lockedEvidence->status = PaymentEvidenceStatus::Rejected;
            $lockedEvidence->save();

            $event = new DealEvent;
            $event->deal_id = $lockedDeal->id;
            $event->actor_user_id = $business->id;
            $event->type = DealEventType::PaymentRejected;
            $event->previous_status = $lockedDeal->status;
            $event->new_status = $lockedDeal->status;
            $event->metadata = [
                'payment_evidence_id' => $lockedEvidence->id,
                'reason' => $reason,
            ];
            $event->save();

            return $lockedEvidence->loadMissing('ambassador');
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function writeEvent(
        Deal $deal,
        User $actor,
        DealEventType $type,
        DealStatus $previous,
        array $metadata,
    ): void {
        $event = new DealEvent;
        $event->deal_id = $deal->id;
        $event->actor_user_id = $actor->id;
        $event->type = $type;
        $event->previous_status = $previous;
        $event->new_status = $deal->status;
        $event->metadata = $metadata;
        $event->save();
    }

    private function assertConfirmable(Deal $deal): void
    {
        if (! $deal->status->allowsConfirmation() || $deal->status->isSealed()) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'This Deal cannot be confirmed in its current state.',
                409,
            ));
        }
    }

    private function assertBusinessOwner(User $user, Deal $deal): void
    {
        if (! $user->isBusiness()) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }

        if ($deal->business_user_id !== $user->id) {
            throw new ModelNotFoundException;
        }
    }

    private function withShowRelations(Deal $deal): Deal
    {
        return $deal->load(['business', 'ambassador', 'campaign', 'campaignVersion', 'events.actor', 'commission']);
    }
}
