<?php

namespace App\Services\Deals;

use App\Enums\DealEventType;
use App\Enums\DealStatus;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\User;
use App\Services\Notifications\DealCancellationNotificationDispatcher;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class DealCancellationService
{
    public function __construct(
        private readonly DealCancellationNotificationDispatcher $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function cancel(User $user, Deal $deal, array $attributes): Deal
    {
        $this->assertDealParty($user, $deal);

        $cancelled = false;

        $result = DB::transaction(function () use ($user, $deal, $attributes, &$cancelled): Deal {
            $locked = Deal::query()->whereKey($deal->id)->lockForUpdate()->firstOrFail();

            if ($locked->business_user_id !== $user->id && $locked->ambassador_user_id !== $user->id) {
                throw new ModelNotFoundException;
            }

            if ($locked->status->isCancelled()) {
                return $this->withShowRelations($locked);
            }

            if (! $locked->status->allowsCancellation()) {
                throw new HttpResponseException(ApiResponse::error(
                    ApiErrorCode::CONFLICT,
                    'This Deal cannot be cancelled in its current state.',
                    409,
                ));
            }

            $reason = trim((string) $attributes['reason']);
            $previous = $locked->status;
            $locked->status = DealStatus::Cancelled;
            $locked->cancelled_at = now();
            $locked->save();

            $event = new DealEvent;
            $event->deal_id = $locked->id;
            $event->actor_user_id = $user->id;
            $event->type = DealEventType::Cancelled;
            $event->previous_status = $previous;
            $event->new_status = DealStatus::Cancelled;
            $event->metadata = [
                'reason' => $reason,
                'cancelled_at' => $locked->cancelled_at?->toIso8601String(),
            ];
            $event->save();

            $cancelled = true;

            return $this->withShowRelations($locked);
        });

        if ($cancelled) {
            $this->notifications->notifyCancelled($result);
        }

        return $result;
    }

    private function assertDealParty(User $user, Deal $deal): void
    {
        if (! $user->isBusiness() && ! $user->isAmbassador()) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }

        if ($user->isBusiness() && $deal->business_user_id !== $user->id) {
            throw new ModelNotFoundException;
        }

        if ($user->isAmbassador() && $deal->ambassador_user_id !== $user->id) {
            throw new ModelNotFoundException;
        }
    }

    private function withShowRelations(Deal $deal): Deal
    {
        return $deal->load(['business', 'ambassador', 'campaign', 'campaignVersion', 'events.actor', 'commission'])
            ->loadCount([
                'disputes as open_dispute_count' => fn ($disputes) => $disputes->open(),
            ]);
    }
}
