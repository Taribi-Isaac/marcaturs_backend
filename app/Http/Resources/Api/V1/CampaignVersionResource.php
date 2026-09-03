<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CampaignVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CampaignVersion
 */
class CampaignVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'campaign_id' => $this->campaign_id,
            'version_number' => $this->version_number,
            'status' => $this->status->value,
            'product_name' => $this->product_name,
            'product_description' => $this->product_description,
            'pricing_method' => $this->pricing_method?->value,
            'price_amount' => $this->price_amount,
            'price_currency' => $this->price_currency,
            'service_area' => $this->service_area,
            'commission_type' => $this->commission_type?->value,
            'commission_rate' => $this->commission_rate,
            'commission_amount' => $this->commission_amount,
            'commission_trigger' => $this->commission_trigger?->value,
            'commission_trigger_description' => $this->commission_trigger_description,
            'commission_payment_deadline_days' => $this->commission_payment_deadline_days,
            'minimum_qualifying_amount' => $this->minimum_qualifying_amount,
            'qualifying_conditions' => $this->qualifying_conditions,
            'refund_cancellation_rules' => $this->refund_cancellation_rules,
            'approved_claims' => $this->approved_claims,
            'prohibited_claims' => $this->prohibited_claims,
            'brand_use_rules' => $this->brand_use_rules,
            'geographic_customer_restrictions' => $this->geographic_customer_restrictions,
            'approved_copy' => $this->approved_copy,
            'marketing_links' => $this->marketing_links ?? [],
            'payment_destination_name' => $this->payment_destination_name,
            'payment_provider' => $this->payment_provider,
            'payment_account_identifier' => $this->payment_account_identifier,
            'payment_instructions' => $this->payment_instructions,
            'payment_contact' => $this->payment_contact,
            'terms' => $this->terms,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
