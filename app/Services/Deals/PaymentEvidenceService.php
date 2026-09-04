<?php

namespace App\Services\Deals;

use App\Enums\DealEventType;
use App\Enums\PaymentEvidenceKind;
use App\Enums\PaymentEvidenceStatus;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\PaymentEvidence;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PaymentEvidenceService
{
    public function __construct(
        private readonly PaymentEvidenceStore $files,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function submit(User $ambassador, Deal $deal, array $attributes, ?UploadedFile $file): PaymentEvidence
    {
        if (! $ambassador->isAmbassador()) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }

        $this->assertDealAmbassador($ambassador, $deal);

        $kind = $attributes['kind'] instanceof PaymentEvidenceKind
            ? $attributes['kind']
            : PaymentEvidenceKind::from((string) $attributes['kind']);

        $stored = $file instanceof UploadedFile ? $this->files->store($deal, $file) : null;

        try {
            return DB::transaction(function () use ($ambassador, $deal, $attributes, $kind, $stored): PaymentEvidence {
                $locked = Deal::query()->whereKey($deal->id)->lockForUpdate()->firstOrFail();

                if ($locked->status->isCancelled()) {
                    throw new HttpResponseException(ApiResponse::error(
                        ApiErrorCode::CONFLICT,
                        'Payment evidence cannot be submitted for a cancelled Deal.',
                        409,
                    ));
                }

                $evidence = new PaymentEvidence;
                $evidence->deal_id = $locked->id;
                $evidence->ambassador_user_id = $ambassador->id;
                $evidence->kind = $kind;
                $evidence->status = PaymentEvidenceStatus::Submitted;
                $evidence->reference_number = $attributes['reference_number'] ?? null;
                $evidence->amount = $attributes['amount'] ?? null;
                $evidence->currency = $this->resolveCurrency($locked, $attributes);
                $evidence->paid_on = $attributes['paid_on'] ?? null;
                $evidence->note = $attributes['note'] ?? null;
                $evidence->submitted_at = now();

                if ($stored !== null) {
                    $evidence->disk = $stored['disk'];
                    $evidence->path = $stored['path'];
                    $evidence->original_filename = $stored['original_filename'];
                    $evidence->mime_type = $stored['mime_type'];
                    $evidence->size_bytes = $stored['size_bytes'];
                }

                $evidence->save();

                $event = new DealEvent;
                $event->deal_id = $locked->id;
                $event->actor_user_id = $ambassador->id;
                $event->type = DealEventType::PaymentEvidenceSubmitted;
                $event->previous_status = $locked->status;
                $event->new_status = $locked->status;
                $event->metadata = [
                    'payment_evidence_id' => $evidence->id,
                    'kind' => $kind->value,
                    'has_file' => $stored !== null,
                ];
                $event->save();

                return $evidence->fresh(['ambassador']) ?? $evidence;
            });
        } catch (\Throwable $exception) {
            if ($stored !== null) {
                $this->files->deleteStored($stored['disk'], $stored['path']);
            }

            throw $exception;
        }
    }

    /**
     * @return Collection<int, PaymentEvidence>
     */
    public function listForParticipant(User $user, Deal $deal): Collection
    {
        $this->assertDealParty($user, $deal);

        return PaymentEvidence::query()
            ->where('deal_id', $deal->id)
            ->with('ambassador')
            ->orderBy('id')
            ->get();
    }

    public function showForParticipant(User $user, Deal $deal, PaymentEvidence $evidence): PaymentEvidence
    {
        $this->assertDealParty($user, $deal);
        $this->assertEvidenceBelongsToDeal($deal, $evidence);

        return $evidence->loadMissing('ambassador');
    }

    public function streamForParticipant(User $user, Deal $deal, PaymentEvidence $evidence)
    {
        $found = $this->showForParticipant($user, $deal, $evidence);

        if (! $found->hasFile()) {
            throw new ModelNotFoundException;
        }

        return $this->files->stream($found);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function resolveCurrency(Deal $deal, array $attributes): ?string
    {
        if (! array_key_exists('amount', $attributes) || $attributes['amount'] === null || $attributes['amount'] === '') {
            return isset($attributes['currency']) ? strtoupper((string) $attributes['currency']) : null;
        }

        $currency = $attributes['currency'] ?? $deal->price_currency ?? 'NGN';

        return strtoupper((string) $currency);
    }

    private function assertDealAmbassador(User $user, Deal $deal): void
    {
        if ($deal->ambassador_user_id !== $user->id) {
            throw new ModelNotFoundException;
        }
    }

    private function assertDealParty(User $user, Deal $deal): void
    {
        if (! $user->isBusiness() && ! $user->isAmbassador()) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }

        if ($user->isAmbassador() && $deal->ambassador_user_id !== $user->id) {
            throw new ModelNotFoundException;
        }

        if ($user->isBusiness() && $deal->business_user_id !== $user->id) {
            throw new ModelNotFoundException;
        }
    }

    private function assertEvidenceBelongsToDeal(Deal $deal, PaymentEvidence $evidence): void
    {
        if ($evidence->deal_id !== $deal->id) {
            throw new ModelNotFoundException;
        }
    }
}
