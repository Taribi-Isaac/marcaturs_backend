<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\OverallVerificationStatus;
use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Campaign
 */
class MarketplaceCampaignCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $version = $this->currentVersion;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status->value,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
                'listing_status' => $this->category->listing_status->value,
            ]),
            'business' => [
                'legal_name' => $this->user?->businessProfile?->legal_name,
                'trading_name' => $this->user?->businessProfile?->trading_name,
                'verification_status' => ($this->marketplace_verification_status ?? OverallVerificationStatus::NotStarted)->value,
            ],
            'product_name' => $version?->product_name,
            'product_description' => $version?->product_description,
            'pricing_method' => $version?->pricing_method?->value,
            'price_amount' => $version?->price_amount,
            'price_currency' => $version?->price_currency,
            'commission_type' => $version?->commission_type?->value,
            'commission_rate' => $version?->commission_rate,
            'commission_amount' => $version?->commission_amount,
            'commission_trigger' => $version?->commission_trigger?->value,
            'service_area' => $version?->service_area,
            'version_number' => $version?->version_number,
            'is_featured' => (bool) $this->is_featured,
            'listing_starts_at' => $this->listing_starts_at?->toIso8601String(),
            'listing_expires_at' => $this->listing_expires_at?->toIso8601String(),
        ];
    }
}
