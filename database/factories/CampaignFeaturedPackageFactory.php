<?php

namespace Database\Factories;

use App\Models\CampaignFeaturedPackage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignFeaturedPackage>
 */
class CampaignFeaturedPackageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => '7-day Featured',
            'duration_days' => 7,
            'amount_minor' => 250000,
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
