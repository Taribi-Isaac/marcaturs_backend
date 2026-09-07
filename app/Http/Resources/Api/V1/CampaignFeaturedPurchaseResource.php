<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CampaignFeaturedPurchase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CampaignFeaturedPurchase
 */
class CampaignFeaturedPurchaseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'campaign_id' => $this->campaign_id,
            'package_name' => $this->package_name,
            'duration_days' => $this->duration_days,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'is_active' => $this->isCurrentlyActive(),
            'payment' => new PlatformPaymentResource($this->whenLoaded('payment')),
        ];
    }
}
