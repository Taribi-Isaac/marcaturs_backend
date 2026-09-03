<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CampaignExtension;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CampaignExtension
 */
class CampaignExtensionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'campaign_id' => $this->campaign_id,
            'duration_days' => $this->duration_days,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'previous_status' => $this->previous_status->value,
            'resulting_status' => $this->resulting_status->value,
            'previous_listing_expires_at' => $this->previous_listing_expires_at?->toIso8601String(),
            'resulting_listing_expires_at' => $this->resulting_listing_expires_at?->toIso8601String(),
            'applied_at' => $this->applied_at?->toIso8601String(),
            'payment' => new PlatformPaymentResource($this->whenLoaded('payment')),
        ];
    }
}
