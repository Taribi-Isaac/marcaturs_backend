<?php

namespace Database\Factories;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->business(),
            'category_id' => Category::factory(),
            'title' => 'Draft campaign',
            'status' => CampaignStatus::Draft,
            'is_featured' => false,
        ];
    }
}
