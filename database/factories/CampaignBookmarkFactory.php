<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\CampaignBookmark;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignBookmark>
 */
class CampaignBookmarkFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ambassador_user_id' => User::factory()->ambassador(),
            'campaign_id' => Campaign::factory(),
        ];
    }
}
