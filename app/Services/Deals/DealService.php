<?php

namespace App\Services\Deals;

use App\Enums\CampaignVersionStatus;
use App\Enums\DealEventType;
use App\Enums\DealStatus;
use App\Models\Campaign;
use App\Models\CampaignVersion;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class DealService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $ambassador, array $attributes): Deal
    {
        if (! $ambassador->isAmbassador()) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }

        return DB::transaction(function () use ($ambassador, $attributes): Deal {
            $campaign = $this->lockEligibleCampaign((int) $attributes['campaign_id']);
            $version = $this->requireCurrentPublishedVersion($campaign);

            $deal = new Deal;
            $deal->business_user_id = $campaign->user_id;
            $deal->ambassador_user_id = $ambassador->id;
            $deal->campaign_id = $campaign->id;
            $deal->campaign_version_id = $version->id;
            $deal->status = DealStatus::PaymentPending;
            $deal->product_name = $version->product_name;
            $deal->pricing_method = $version->pricing_method;
            $deal->price_amount = $version->price_amount;
            $deal->price_currency = $version->price_currency;
            $deal->commission_type = $version->commission_type;
            $deal->commission_rate = $version->commission_rate;
            $deal->commission_amount = $version->commission_amount;
            $deal->commission_trigger = $version->commission_trigger;
            $deal->commission_trigger_description = $version->commission_trigger_description;
            $deal->commission_payment_deadline_days = (int) $version->commission_payment_deadline_days;
            $deal->minimum_qualifying_amount = $version->minimum_qualifying_amount;
            $deal->qualifying_conditions = $version->qualifying_conditions;
            $deal->expected_transaction_amount = $attributes['expected_transaction_amount'] ?? null;
            $deal->save();

            $event = new DealEvent;
            $event->deal_id = $deal->id;
            $event->actor_user_id = $ambassador->id;
            $event->type = DealEventType::Created;
            $event->previous_status = null;
            $event->new_status = DealStatus::PaymentPending;
            $event->metadata = [
                'campaign_id' => $campaign->id,
                'campaign_version_id' => $version->id,
                'campaign_version_number' => $version->version_number,
            ];
            $event->save();

            return $this->withShowRelations($deal);
        });
    }

    public function listForParticipant(User $user, int $perPage): LengthAwarePaginator
    {
        $this->assertParticipantRole($user);

        $query = Deal::query()->with(['business', 'ambassador', 'campaign', 'campaignVersion']);

        if ($user->isAmbassador()) {
            $query->where('ambassador_user_id', $user->id);
        } else {
            $query->where('business_user_id', $user->id);
        }

        return $query
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function showForParticipant(User $user, Deal $deal): Deal
    {
        $this->assertParticipant($user, $deal);

        return $this->withShowRelations($deal);
    }

    private function lockEligibleCampaign(int $campaignId): Campaign
    {
        $campaign = Campaign::query()
            ->whereKey($campaignId)
            ->lockForUpdate()
            ->first();

        if ($campaign === null) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::VALIDATION_ERROR,
                'The given data was invalid.',
                400,
                ['campaign_id' => ['The selected campaign is invalid.']],
            ));
        }

        if (! $campaign->status->allowsNewDeals()) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::BUSINESS_VALIDATION,
                'New Deals can only be created for active or expiring campaigns.',
                422,
            ));
        }

        $campaign->load('currentVersion');

        return $campaign;
    }

    private function requireCurrentPublishedVersion(Campaign $campaign): CampaignVersion
    {
        $version = $campaign->currentVersion;

        if (
            $version === null
            || $campaign->current_campaign_version_id !== $version->id
            || $version->status !== CampaignVersionStatus::Published
            || $version->campaign_id !== $campaign->id
        ) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::BUSINESS_VALIDATION,
                'A published campaign version is required before a Deal can be created.',
                422,
            ));
        }

        if ($version->commission_type === null || $version->commission_trigger === null || $version->commission_payment_deadline_days === null) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::BUSINESS_VALIDATION,
                'The published campaign version is missing required commercial terms.',
                422,
            ));
        }

        return $version;
    }

    private function withShowRelations(Deal $deal): Deal
    {
        return $deal->load(['business', 'ambassador', 'campaign', 'campaignVersion', 'events.actor', 'commission']);
    }

    private function assertParticipantRole(User $user): void
    {
        if (! $user->isBusiness() && ! $user->isAmbassador()) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }
    }

    private function assertParticipant(User $user, Deal $deal): void
    {
        $this->assertParticipantRole($user);

        if ($user->isAmbassador() && $deal->ambassador_user_id !== $user->id) {
            throw new ModelNotFoundException;
        }

        if ($user->isBusiness() && $deal->business_user_id !== $user->id) {
            throw new ModelNotFoundException;
        }
    }
}
