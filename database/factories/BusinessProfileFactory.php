<?php

namespace Database\Factories;

use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessProfile>
 */
class BusinessProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->business(),
            'legal_name' => fake()->company(),
            'trading_name' => fake()->optional()->company(),
            'description' => fake()->optional()->paragraph(),
            'category' => fake()->optional()->word(),
            'address' => fake()->optional()->address(),
            'operating_location' => fake()->optional()->city(),
            'contact_email' => fake()->optional()->companyEmail(),
            'contact_phone' => fake()->optional()->numerify('080########'),
            'website' => fake()->optional()->url(),
            'social_links' => [
                'instagram' => 'https://instagram.com/example',
            ],
        ];
    }
}
