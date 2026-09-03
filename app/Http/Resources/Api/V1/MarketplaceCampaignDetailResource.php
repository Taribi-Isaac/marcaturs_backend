<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\OverallVerificationStatus;
use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Campaign
 */
class MarketplaceCampaignDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $version = $this->currentVersion;
        $profile = $this->user?->businessProfile;

        return [
            ...(new MarketplaceCampaignCardResource($this))->toArray($request),
            'business' => [
                'legal_name' => $profile?->legal_name,
                'trading_name' => $profile?->trading_name,
                'description' => $profile?->description,
                'operating_location' => $profile?->operating_location,
                'website' => $profile?->website,
                'verification_status' => ($this->marketplace_verification_status ?? OverallVerificationStatus::NotStarted)->value,
            ],
            'commission_trigger_description' => $version?->commission_trigger_description,
            'commission_payment_deadline_days' => $version?->commission_payment_deadline_days,
            'minimum_qualifying_amount' => $version?->minimum_qualifying_amount,
            'qualifying_conditions' => $version?->qualifying_conditions,
            'refund_cancellation_rules' => $version?->refund_cancellation_rules,
            'approved_claims' => $version?->approved_claims,
            'prohibited_claims' => $version?->prohibited_claims,
            'brand_use_rules' => $version?->brand_use_rules,
            'geographic_customer_restrictions' => $version?->geographic_customer_restrictions,
            'approved_copy' => $version?->approved_copy,
            'marketing_links' => $version?->marketing_links ?? [],
            'terms' => $version?->terms,
            'payment_destination_name' => $version?->payment_destination_name,
            'payment_provider' => $version?->payment_provider,
            'marketing_resources' => CampaignMarketingResourceResource::collection(
                $this->whenLoaded('marketingResources'),
            ),
        ];
    }
}
