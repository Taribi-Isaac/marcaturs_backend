<?php

namespace App\Services\Campaigns;

use App\Http\Requests\Api\V1\Marketplace\CampaignBookmarkIndexRequest;
use App\Models\Campaign;
use App\Models\CampaignBookmark;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;

class CampaignBookmarkService
{
    public function __construct(
        private readonly CampaignDiscoveryService $discovery,
    ) {}

    public function save(User $ambassador, int $campaignId): CampaignBookmark
    {
        $campaign = Campaign::query()->discoverable()->whereKey($campaignId)->first();

        if ($campaign === null) {
            throw (new ModelNotFoundException)->setModel(Campaign::class, [$campaignId]);
        }

        $existing = $this->owned($ambassador, $campaign->id);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return CampaignBookmark::query()->create([
                'ambassador_user_id' => $ambassador->id,
                'campaign_id' => $campaign->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->owned($ambassador, $campaign->id) ?? throw new ModelNotFoundException;
        }
    }

    public function remove(User $ambassador, int $campaignId): void
    {
        CampaignBookmark::query()
            ->where('ambassador_user_id', $ambassador->id)
            ->where('campaign_id', $campaignId)
            ->delete();
    }

    public function list(User $ambassador, CampaignBookmarkIndexRequest $request): LengthAwarePaginator
    {
        $perPage = min(
            max(1, (int) $request->input('per_page', config('api.pagination.default_per_page'))),
            (int) config('api.pagination.max_per_page'),
        );

        $paginator = CampaignBookmark::query()
            ->where('ambassador_user_id', $ambassador->id)
            ->with([
                'campaign.category',
                'campaign.currentVersion',
                'campaign.user.businessProfile',
                'campaign.cover',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $campaigns = $paginator->getCollection()
            ->map(fn (CampaignBookmark $bookmark) => $bookmark->campaign)
            ->filter()
            ->values();

        $discoverableIds = Campaign::query()
            ->discoverable()
            ->whereIn('id', $campaigns->pluck('id'))
            ->pluck('id')
            ->all();

        $discoverableCampaigns = $campaigns->filter(
            fn (Campaign $campaign) => in_array($campaign->id, $discoverableIds, true),
        );
        $statuses = $this->discovery->verificationStatuses($discoverableCampaigns);

        foreach ($paginator->getCollection() as $bookmark) {
            $campaign = $bookmark->campaign;
            $bookmark->currentlyDiscoverable = $campaign !== null
                && in_array($campaign->id, $discoverableIds, true);

            if ($bookmark->currentlyDiscoverable && $campaign !== null) {
                $campaign->setAttribute(
                    'marketplace_verification_status',
                    $statuses[$campaign->user_id] ?? null,
                );
            }
        }

        return $paginator;
    }

    private function owned(User $ambassador, int $campaignId): ?CampaignBookmark
    {
        return CampaignBookmark::query()
            ->where('ambassador_user_id', $ambassador->id)
            ->where('campaign_id', $campaignId)
            ->first();
    }
}
