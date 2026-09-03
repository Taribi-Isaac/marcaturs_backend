<?php

namespace Database\Factories;

use App\Models\CampaignExtensionPackage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignExtensionPackage>
 */
class CampaignExtensionPackageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => '30 additional days',
            'duration_days' => 30,
            'amount_minor' => 500000,
            'currency' => 'NGN',
            'is_active' => true,
            'sort_order' => 1,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
