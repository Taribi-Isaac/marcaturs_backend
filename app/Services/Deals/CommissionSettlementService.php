<?php

namespace App\Services\Deals;

use App\Enums\CommissionEventType;
use App\Enums\CommissionStatus;
use App\Models\Commission;
use App\Models\CommissionEvent;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CommissionSettlementService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function markPaid(User $business, Commission $commission, array $attributes): Commission
    {
        if (! $business->isBusiness()) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }

        if ($commission->business_user_id !== $business->id) {
            throw new ModelNotFoundException;
        }

        try {
            return DB::transaction(function () use ($business, $commission, $attributes): Commission {
                $locked = Commission::query()->whereKey($commission->id)->lockForUpdate()->firstOrFail();

                if ($locked->business_user_id !== $business->id) {
                    throw new ModelNotFoundException;
                }

                if ($locked->status->isPaid()) {
                    return $this->withShowRelations($locked);
                }

                if ($locked->status->isReceived()) {
                    throw new HttpResponseException(ApiResponse::error(
                        ApiErrorCode::CONFLICT,
                        'A received Commission cannot be marked paid.',
                        409,
                    ));
                }

                if (! $locked->status->isDue()) {
                    throw new HttpResponseException(ApiResponse::error(
                        ApiErrorCode::CONFLICT,
                        'This Commission cannot be marked paid in its current state.',
                        409,
                    ));
                }

                $previous = $locked->status;
                $locked->status = CommissionStatus::Paid;
                $locked->paid_at = now();
                $locked->payment_reference = $this->optionalText($attributes['payment_reference'] ?? null);
                $locked->payment_note = $this->optionalText($attributes['payment_note'] ?? null);
                $locked->save();

                $this->writeEvent($locked, $business, CommissionEventType::Paid, $previous, [
                    'has_payment_reference' => $locked->payment_reference !== null,
                    'has_payment_note' => $locked->payment_note !== null,
                    'paid_at' => $locked->paid_at?->toIso8601String(),
                ]);

                Log::info('Commission marked paid', [
                    'commission_id' => $locked->id,
                    'deal_id' => $locked->deal_id,
                    'actor_user_id' => $business->id,
                    'previous_status' => $previous->value,
                    'new_status' => $locked->status->value,
                    'has_payment_reference' => $locked->payment_reference !== null,
                ]);

                return $this->withShowRelations($locked);
            });
        } catch (UniqueConstraintViolationException|QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $current = Commission::query()->whereKey($commission->id)->firstOrFail();

            if ($current->business_user_id !== $business->id) {
                throw new ModelNotFoundException;
            }

            if ($current->status->isPaid()) {
                return $this->withShowRelations($current);
            }

            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'This Commission cannot be marked paid in its current state.',
                409,
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function confirmReceived(User $ambassador, Commission $commission, array $attributes): Commission
    {
        unset($attributes);

        if (! $ambassador->isAmbassador()) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }

        if ($commission->ambassador_user_id !== $ambassador->id) {
            throw new ModelNotFoundException;
        }

        try {
            return DB::transaction(function () use ($ambassador, $commission): Commission {
                $locked = Commission::query()->whereKey($commission->id)->lockForUpdate()->firstOrFail();

                if ($locked->ambassador_user_id !== $ambassador->id) {
                    throw new ModelNotFoundException;
                }

                if ($locked->status->isReceived()) {
                    return $this->withShowRelations($locked);
                }

                if ($locked->status->isDue()) {
                    throw new HttpResponseException(ApiResponse::error(
                        ApiErrorCode::BUSINESS_VALIDATION,
                        'Commission receipt can only be confirmed after the Business has marked the Commission paid.',
                        422,
                    ));
                }

                if (! $locked->status->isPaid()) {
                    throw new HttpResponseException(ApiResponse::error(
                        ApiErrorCode::CONFLICT,
                        'This Commission cannot be confirmed received in its current state.',
                        409,
                    ));
                }

                $previous = $locked->status;
                $locked->status = CommissionStatus::Received;
                $locked->received_at = now();
                $locked->save();

                $this->writeEvent($locked, $ambassador, CommissionEventType::Received, $previous, [
                    'received_at' => $locked->received_at?->toIso8601String(),
                ]);

                Log::info('Commission receipt confirmed', [
                    'commission_id' => $locked->id,
                    'deal_id' => $locked->deal_id,
                    'actor_user_id' => $ambassador->id,
                    'previous_status' => $previous->value,
                    'new_status' => $locked->status->value,
                ]);

                return $this->withShowRelations($locked);
            });
        } catch (UniqueConstraintViolationException|QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $current = Commission::query()->whereKey($commission->id)->firstOrFail();

            if ($current->ambassador_user_id !== $ambassador->id) {
                throw new ModelNotFoundException;
            }

            if ($current->status->isReceived()) {
                return $this->withShowRelations($current);
            }

            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'This Commission cannot be confirmed received in its current state.',
                409,
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function writeEvent(
        Commission $commission,
        User $actor,
        CommissionEventType $type,
        CommissionStatus $previous,
        array $metadata,
    ): void {
        $event = new CommissionEvent;
        $event->commission_id = $commission->id;
        $event->actor_user_id = $actor->id;
        $event->type = $type;
        $event->previous_status = $previous;
        $event->new_status = $commission->status;
        $event->metadata = $metadata;
        $event->save();
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return $exception instanceof UniqueConstraintViolationException
            || $exception->getCode() === '23000'
            || str_contains(strtolower($exception->getMessage()), 'unique');
    }

    private function optionalText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function withShowRelations(Commission $commission): Commission
    {
        return $commission->loadMissing(['deal', 'business', 'ambassador']);
    }
}
