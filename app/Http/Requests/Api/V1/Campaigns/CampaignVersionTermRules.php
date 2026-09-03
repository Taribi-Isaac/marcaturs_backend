<?php

namespace App\Http\Requests\Api\V1\Campaigns;

use App\Enums\CommissionTrigger;
use App\Enums\CommissionType;
use App\Enums\PricingMethod;
use Illuminate\Validation\Rule;

final class CampaignVersionTermRules
{
    /**
     * @return array<string, mixed>
     */
    public static function draft(bool $requiredProductName): array
    {
        $product = $requiredProductName
        ? ['required', 'string', 'max:255']
        : ['sometimes', 'nullable', 'string', 'max:255'];

        return [
            'product_name' => $product,
            'product_description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'pricing_method' => ['sometimes', 'nullable', Rule::enum(PricingMethod::class)],
            'price_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'price_currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'service_area' => ['sometimes', 'nullable', 'string', 'max:255'],
            'commission_type' => ['sometimes', 'nullable', Rule::enum(CommissionType::class)],
            'commission_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'commission_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'commission_trigger' => ['sometimes', 'nullable', Rule::enum(CommissionTrigger::class)],
            'commission_trigger_description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'commission_payment_deadline_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            'minimum_qualifying_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'qualifying_conditions' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'refund_cancellation_rules' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'approved_claims' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'prohibited_claims' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'brand_use_rules' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'geographic_customer_restrictions' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'approved_copy' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'marketing_links' => ['sometimes', 'nullable', 'array'],
            'marketing_links.*' => ['string', 'url', 'max:2048'],
            'payment_destination_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'payment_provider' => ['sometimes', 'nullable', 'string', 'max:255'],
            'payment_account_identifier' => ['sometimes', 'nullable', 'string', 'max:255'],
            'payment_instructions' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'payment_contact' => ['sometimes', 'nullable', 'string', 'max:255'],
            'terms' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ];
    }
}
