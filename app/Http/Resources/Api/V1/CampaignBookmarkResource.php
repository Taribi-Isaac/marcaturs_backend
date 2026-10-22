<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CampaignBookmark;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CampaignBookmark
 */
class CampaignBookmarkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $campaign = $this->campaign;

        return [
            'bookmarked_at' => $this->created_at?->toIso8601String(),
            'discoverable' => $this->currentlyDiscoverable,
            'campaign' => $this->currentlyDiscoverable
                ? (new MarketplaceCampaignCardResource($campaign))->resolve($request)
                : [
                    'id' => $campaign->id,
                    'title' => $campaign->title,
                    'status' => $campaign->status->value,
                ],
        ];
    }
}
