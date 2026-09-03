<?php

namespace Database\Factories;

use App\Enums\CampaignMarketingResourceType;
use App\Models\Campaign;
use App\Models\CampaignMarketingResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignMarketingResource>
 */
class CampaignMarketingResourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'uploaded_by' => User::factory()->business(),
            'type' => CampaignMarketingResourceType::Image,
            'title' => 'Product flyer',
            'description' => null,
            'disk' => 'campaign_media',
            'path' => 'campaigns/1/resources/example.jpg',
            'original_filename' => 'flyer.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'sort_order' => 0,
        ];
    }
}
