<?php

namespace Database\Factories;

use App\Models\AmbassadorProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AmbassadorProfile>
 */
class AmbassadorProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->ambassador(),
            'display_name' => fake()->name(),
            'profile_description' => fake()->optional()->paragraph(),
            'location' => fake()->optional()->city(),
            'skills' => ['social-media', 'field-sales'],
            'marketing_interests' => ['retail', 'education'],
            'experience' => fake()->optional()->sentence(),
        ];
    }
}
