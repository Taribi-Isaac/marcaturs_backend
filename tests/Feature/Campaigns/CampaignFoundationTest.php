<?php

namespace Tests\Feature\Campaigns;

use App\Enums\CampaignStatus;
use App\Models\BusinessProfile;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CampaignFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_campaign_access_is_rejected(): void
    {
        $this->getJson('/api/v1/campaigns')
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);
    }

    public function test_ambassador_and_admin_cannot_create_campaigns(): void
    {
        $category = Category::factory()->create();

        Sanctum::actingAs(User::factory()->ambassador()->create());
        $this->postJson('/api/v1/campaigns', [
            'title' => 'Should fail',
            'category_id' => $category->id,
        ])->assertStatus(403)->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson('/api/v1/campaigns', [
            'title' => 'Should fail',
            'category_id' => $category->id,
        ])->assertStatus(403);
    }

    public function test_restricted_business_cannot_access_campaigns(): void
    {
        Sanctum::actingAs(User::factory()->business()->restricted()->create());

        $this->getJson('/api/v1/campaigns')
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);
    }

    public function test_campaign_creation_requires_a_completed_profile(): void
    {
        $category = Category::factory()->create();
        Sanctum::actingAs(User::factory()->business()->create());

        $this->postJson('/api/v1/campaigns', [
            'title' => 'Solar starter kit',
            'category_id' => $category->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ApiErrorCode::BUSINESS_VALIDATION);
    }

    public function test_business_owns_draft_campaigns_and_cannot_access_others(): void
    {
        $owner = User::factory()->business()->create();
        BusinessProfile::factory()->for($owner)->create();
        $category = Category::factory()->create(['name' => 'Technology', 'slug' => 'technology']);
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/campaigns', [
            'title' => 'Classroom internet package',
            'category_id' => $category->id,
            'status' => CampaignStatus::Active->value,
            'user_id' => 999,
            'is_featured' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Classroom internet package')
            ->assertJsonPath('data.status', CampaignStatus::Draft->value)
            ->assertJsonPath('data.category.slug', 'technology')
            ->assertJsonMissingPath('data.user_id')
            ->assertJsonMissingPath('data.is_featured');

        $campaign = Campaign::query()->firstOrFail();
        $this->assertSame($owner->id, $campaign->user_id);
        $this->assertFalse($campaign->is_featured);

        $this->getJson('/api/v1/campaigns')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $campaign->id);

        $this->patchJson('/api/v1/campaigns/'.$campaign->id, [
            'title' => 'Updated classroom package',
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated classroom package');

        $intruder = User::factory()->business()->create();
        BusinessProfile::factory()->for($intruder)->create();
        Sanctum::actingAs($intruder);

        $this->getJson('/api/v1/campaigns/'.$campaign->id)
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);

        $this->patchJson('/api/v1/campaigns/'.$campaign->id, [
            'title' => 'Stolen title',
        ])->assertStatus(403);

        $this->assertSame('Updated classroom package', $campaign->fresh()->title);
    }

    public function test_inactive_and_prohibited_categories_cannot_be_assigned(): void
    {
        $user = User::factory()->business()->create();
        BusinessProfile::factory()->for($user)->create();
        $inactive = Category::factory()->inactive()->create();
        $prohibited = Category::factory()->prohibited()->create();
        $restricted = Category::factory()->restricted()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/campaigns', [
            'title' => 'Inactive category campaign',
            'category_id' => $inactive->id,
        ])->assertStatus(422)->assertJsonPath('error.code', ApiErrorCode::BUSINESS_VALIDATION);

        $this->postJson('/api/v1/campaigns', [
            'title' => 'Prohibited category campaign',
            'category_id' => $prohibited->id,
        ])->assertStatus(422)->assertJsonPath('error.code', ApiErrorCode::BUSINESS_VALIDATION);

        $this->postJson('/api/v1/campaigns', [
            'title' => 'Restricted category draft',
            'category_id' => $restricted->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.category.listing_status', 'restricted');
    }

    public function test_missing_category_and_title_are_validation_errors(): void
    {
        $user = User::factory()->business()->create();
        BusinessProfile::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/campaigns', [
            'title' => 'No category',
        ])->assertStatus(400)->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);

        $this->postJson('/api/v1/campaigns', [
            'category_id' => 1,
        ])->assertStatus(400)->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);
    }

    public function test_non_draft_campaigns_cannot_be_updated_in_this_foundation(): void
    {
        $user = User::factory()->business()->create();
        BusinessProfile::factory()->for($user)->create();
        $campaign = Campaign::factory()->for($user)->create([
            'status' => CampaignStatus::Active,
        ]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/campaigns/'.$campaign->id, [
            'title' => 'Should not apply',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::CONFLICT);
    }
}
