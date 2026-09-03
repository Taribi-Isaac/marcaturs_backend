<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\CampaignVersionStatus;
use App\Models\Campaign;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;

class CampaignLifecycleService
{
    public function submit(User $user, Campaign $campaign): Campaign
    {
        $this->assertOwner($user, $campaign);
        $this->assertStatus($campaign, CampaignStatus::Draft, 'Only draft campaigns can be submitted.');
        $this->assertReadyForMarketplace($campaign);

        $campaign->status = CampaignStatus::Submitted;
        $campaign->submitted_at = now();
        $campaign->review_reason = null;
        $campaign->save();

        return $this->fresh($campaign);
    }

    public function approve(User $admin, Campaign $campaign): Campaign
    {
        $this->assertAdmin($admin);
        $this->assertStatus($campaign, CampaignStatus::Submitted, 'Only submitted campaigns can be approved.');
        $this->assertReadyForMarketplace($campaign);

        $campaign->status = CampaignStatus::Approved;
        $campaign->approved_at = now();
        $campaign->review_reason = null;
        $campaign->save();

        return $this->fresh($campaign);
    }

    public function reject(User $admin, Campaign $campaign, string $reason): Campaign
    {
        $this->assertAdmin($admin);
        $this->assertStatus($campaign, CampaignStatus::Submitted, 'Only submitted campaigns can be rejected.');

        $campaign->status = CampaignStatus::Draft;
        $campaign->review_reason = $reason;
        $campaign->save();

        return $this->fresh($campaign);
    }

    public function requestModification(User $admin, Campaign $campaign, string $reason): Campaign
    {
        $this->assertAdmin($admin);
        $this->assertStatus($campaign, CampaignStatus::Submitted, 'Only submitted campaigns can be returned for modification.');

        $campaign->status = CampaignStatus::Draft;
        $campaign->review_reason = $reason;
        $campaign->save();

        return $this->fresh($campaign);
    }

    public function activate(User $admin, Campaign $campaign): Campaign
    {
        $this->assertAdmin($admin);
        $this->assertStatus($campaign, CampaignStatus::Approved, 'Only approved campaigns can be activated.');
        $this->assertReadyForMarketplace($campaign);

        $days = max(1, (int) config('campaigns.free_listing_days'));
        $starts = now();

        $campaign->status = CampaignStatus::Active;
        $campaign->activated_at = $starts;
        $campaign->listing_starts_at = $starts;
        $campaign->listing_expires_at = $starts->copy()->addDays($days);
        $campaign->save();

        return $this->fresh($campaign);
    }

    public function deactivate(User $user, Campaign $campaign): Campaign
    {
        $this->assertOwner($user, $campaign);

        if (! in_array($campaign->status, [CampaignStatus::Active, CampaignStatus::Expiring], true)) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'Only active or expiring campaigns can be deactivated.',
                409,
            ));
        }

        $campaign->status = CampaignStatus::Deactivated;
        $campaign->deactivated_at = now();
        $campaign->save();

        return $this->fresh($campaign);
    }

    public function suspend(User $admin, Campaign $campaign, string $reason): Campaign
    {
        $this->assertAdmin($admin);

        if (! in_array($campaign->status, [CampaignStatus::Active, CampaignStatus::Expiring], true)) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'Only active or expiring campaigns can be suspended.',
                409,
            ));
        }

        $campaign->status = CampaignStatus::Suspended;
        $campaign->suspended_at = now();
        $campaign->review_reason = $reason;
        $campaign->save();

        return $this->fresh($campaign);
    }

    public function close(User $admin, Campaign $campaign, ?string $reason): Campaign
    {
        $this->assertAdmin($admin);

        $closable = [
            CampaignStatus::Submitted,
            CampaignStatus::Approved,
            CampaignStatus::Active,
            CampaignStatus::Expiring,
            CampaignStatus::Suspended,
        ];

        if (! in_array($campaign->status, $closable, true)) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'This campaign cannot be closed in its current state.',
                409,
            ));
        }

        $campaign->status = CampaignStatus::Closed;
        $campaign->closed_at = now();
        $campaign->review_reason = $reason;
        $campaign->save();

        return $this->fresh($campaign);
    }

    /**
     * Apply a confirmed paid listing extension. Does not collect money.
     *
     * @return array{
     *     campaign: Campaign,
     *     previous_status: CampaignStatus,
     *     previous_listing_expires_at: Carbon|null,
     *     resulting_listing_expires_at: Carbon
     * }
     */
    public function applyPaidExtension(Campaign $campaign, int $durationDays): array
    {
        $eligible = [
            CampaignStatus::Active,
            CampaignStatus::Expiring,
            CampaignStatus::Expired,
        ];

        if (! in_array($campaign->status, $eligible, true)) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'This campaign cannot be extended in its current state.',
                409,
            ));
        }

        $this->assertReadyForMarketplace($campaign);

        $previousStatus = $campaign->status;
        $previousExpiry = $campaign->listing_expires_at;
        $now = now();

        if (
            $previousStatus === CampaignStatus::Expired
            || $previousExpiry === null
            || $previousExpiry->lte($now)
        ) {
            $newExpiry = $now->copy()->addDays($durationDays);
        } else {
            $newExpiry = $previousExpiry->copy()->addDays($durationDays);
        }

        $campaign->status = CampaignStatus::Active;
        $campaign->listing_expires_at = $newExpiry;
        $campaign->save();

        return [
            'campaign' => $this->fresh($campaign),
            'previous_status' => $previousStatus,
            'previous_listing_expires_at' => $previousExpiry,
            'resulting_listing_expires_at' => $newExpiry,
        ];
    }

    /**
     * @return array{expiring: int, expired: int}
     */
    public function processDueCampaigns(): array
    {
        $expired = 0;
        $expiring = 0;
        $leadDays = max(0, (int) config('campaigns.expiring_lead_days'));

        $due = Campaign::query()
            ->whereIn('status', [CampaignStatus::Active->value, CampaignStatus::Expiring->value])
            ->whereNotNull('listing_expires_at')
            ->where('listing_expires_at', '<=', now())
            ->get();

        foreach ($due as $campaign) {
            $campaign->status = CampaignStatus::Expired;
            $campaign->expired_at = now();
            $campaign->save();
            $expired++;
        }

        if ($leadDays > 0) {
            $windowStart = now()->addDays($leadDays);

            $warning = Campaign::query()
                ->where('status', CampaignStatus::Active->value)
                ->whereNotNull('listing_expires_at')
                ->where('listing_expires_at', '>', now())
                ->where('listing_expires_at', '<=', $windowStart)
                ->get();

            foreach ($warning as $campaign) {
                $campaign->status = CampaignStatus::Expiring;
                $campaign->save();
                $expiring++;
            }
        }

        return ['expiring' => $expiring, 'expired' => $expired];
    }

    public function adminShow(Campaign $campaign): Campaign
    {
        return $campaign->load(['category', 'currentVersion', 'user']);
    }

    private function assertReadyForMarketplace(Campaign $campaign): void
    {
        $campaign->loadMissing(['currentVersion', 'category']);

        $current = $campaign->currentVersion;

        if ($current === null || $current->status !== CampaignVersionStatus::Published) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::BUSINESS_VALIDATION,
                'A published campaign version is required before this lifecycle action.',
                422,
            ));
        }

        if ($campaign->category === null || ! $campaign->category->isAssignableToCampaigns()) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::BUSINESS_VALIDATION,
                'This campaign category cannot be used in the marketplace.',
                422,
            ));
        }
    }

    private function assertStatus(Campaign $campaign, CampaignStatus $expected, string $message): void
    {
        if ($campaign->status !== $expected) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                $message,
                409,
            ));
        }
    }

    private function assertOwner(User $user, Campaign $campaign): void
    {
        if (! $user->isBusiness() || $campaign->user_id !== $user->id) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }
    }

    private function assertAdmin(User $user): void
    {
        if (! $user->isAdmin()) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }
    }

    private function fresh(Campaign $campaign): Campaign
    {
        return $campaign->refresh()->load(['category', 'currentVersion']);
    }
}
