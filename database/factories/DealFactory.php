<?php

namespace Database\Factories;

use App\Enums\DealStatus;
use App\Models\Campaign;
use App\Models\CampaignVersion;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deal>
 */
class DealFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_user_id' => User::factory()->business(),
            'ambassador_user_id' => User::factory()->ambassador(),
            'campaign_id' => Campaign::factory(),
            'campaign_version_id' => CampaignVersion::factory()->published(),
            'status' => DealStatus::PaymentPending,
            'product_name' => 'Example product',
            'commission_type' => 'percentage',
            'commission_rate' => '10.00',
            'commission_trigger' => 'payment_confirmation',
            'commission_payment_deadline_days' => 7,
        ];
    }
}
