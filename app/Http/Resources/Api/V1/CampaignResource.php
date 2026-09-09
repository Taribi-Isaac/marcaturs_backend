<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Campaign;
use App\Support\Campaigns\OfficialPaymentShare;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Campaign
 */
class CampaignResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status->value,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'current_version' => $this->whenLoaded('currentVersion', function () {
                if ($this->currentVersion === null) {
                    return null;
                }

                return [
                    'id' => $this->currentVersion->id,
                    'version_number' => $this->currentVersion->version_number,
                    'status' => $this->currentVersion->status->value,
                ];
            }),
            'listing_starts_at' => $this->listing_starts_at?->toIso8601String(),
            'listing_expires_at' => $this->listing_expires_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'activated_at' => $this->activated_at?->toIso8601String(),
            'deactivated_at' => $this->deactivated_at?->toIso8601String(),
            'expired_at' => $this->expired_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'review_reason' => $this->review_reason,
            'official_payment' => $this->when(
                $this->current_campaign_version_id !== null,
                fn () => OfficialPaymentShare::references($this->resource),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
