<?php

namespace Database\Factories;

use App\Enums\CampaignVersionStatus;
use App\Enums\CommissionTrigger;
use App\Enums\CommissionType;
use App\Enums\PricingMethod;
use App\Models\Campaign;
use App\Models\CampaignVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignVersion>
 */
class CampaignVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'version_number' => 1,
            'status' => CampaignVersionStatus::Draft,
            'product_name' => 'Example product',
            'product_description' => 'Product description snapshot.',
            'pricing_method' => PricingMethod::Fixed,
            'price_amount' => '50000.00',
            'price_currency' => 'NGN',
            'commission_type' => CommissionType::Percentage,
            'commission_rate' => '10.00',
            'commission_trigger' => CommissionTrigger::PaymentConfirmation,
            'commission_payment_deadline_days' => 7,
            'refund_cancellation_rules' => 'Published refund and cancellation rules.',
            'payment_destination_name' => 'Example Business Ltd',
            'payment_provider' => 'Example Bank',
            'payment_account_identifier' => '0000000000',
            'payment_instructions' => 'Pay the business directly. MarcatursHub does not receive this payment.',
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CampaignVersionStatus::Published,
            'published_at' => now(),
        ]);
    }
}
