<?php

namespace Tests\Feature\Campaigns;

use App\Enums\AccountStatus;
use App\Enums\CampaignStatus;
use App\Enums\CategoryListingStatus;
use App\Models\BusinessProfile;
use App\Models\Campaign;
use App\Models\CampaignBookmark;
use App\Models\CampaignVersion;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CampaignBookmarkTest extends TestCase
{
    use RefreshDatabase;

    public function test_ambassador_can_save_a_discoverable_campaign(): void
    {
        $campaign = $this->discoverableCampaign();
        $ambassador = User::factory()->ambassador()->create();
        Sanctum::actingAs($ambassador);

        $this->putJson('/api/v1/marketplace/campaigns/'.$campaign->id.'/bookmark')
            ->assertOk()
            ->assertJsonPath('data.campaign_id', $campaign->id)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('campaign_bookmarks', [
            'ambassador_user_id' => $ambassador->id,
            'campaign_id' => $campaign->id,
        ]);
    }

    public function test_repeated_save_returns_the_existing_bookmark(): void
    {
        $campaign = $this->discoverableCampaign();
        Sanctum::actingAs(User::factory()->ambassador()->create());

        $first = $this->putJson('/api/v1/marketplace/campaigns/'.$campaign->id.'/bookmark')
            ->assertOk()
            ->json('data.bookmarked_at');

        $this->putJson('/api/v1/marketplace/campaigns/'.$campaign->id.'/bookmark')
            ->assertOk()
            ->assertJsonPath('data.bookmarked_at', $first)
            ->assertJsonPath('data.campaign_id', $campaign->id);

        $this->assertDatabaseCount('campaign_bookmarks', 1);
    }

    public function test_concurrent_save_returns_the_single_existing_bookmark(): void
    {
        $campaign = $this->discoverableCampaign();
        $ambassador = User::factory()->ambassador()->create();
        Sanctum::actingAs($ambassador);

        $inserted = false;
        CampaignBookmark::creating(function () use (&$inserted, $ambassador, $campaign): void {
            if ($inserted) {
                return;
            }

            $inserted = true;
            DB::table('campaign_bookmarks')->insert([
                'ambassador_user_id' => $ambassador->id,
                'campaign_id' => $campaign->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->putJson('/api/v1/marketplace/campaigns/'.$campaign->id.'/bookmark')
            ->assertOk()
            ->assertJsonPath('data.campaign_id', $campaign->id);

        $this->assertDatabaseCount('campaign_bookmarks', 1);
    }

    public function test_repeated_removal_returns_no_content(): void
    {
        $campaign = $this->discoverableCampaign();
        Sanctum::actingAs(User::factory()->ambassador()->create());

        $this->putJson('/api/v1/marketplace/campaigns/'.$campaign->id.'/bookmark')->assertOk();

        $this->deleteJson('/api/v1/marketplace/campaigns/'.$campaign->id.'/bookmark')
            ->assertNoContent();
        $this->deleteJson('/api/v1/marketplace/campaigns/'.$campaign->id.'/bookmark')
            ->assertNoContent();
        $this->deleteJson('/api/v1/marketplace/campaigns/999999/bookmark')
            ->assertNoContent();

        $this->assertDatabaseCount('campaign_bookmarks', 0);
    }

    public function test_guest_cannot_use_bookmark_endpoints(): void
    {
        $campaign = $this->discoverableCampaign();

        $this->putJson('/api/v1/marketplace/campaigns/'.$campaign->id.'/bookmark')
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);
        $this->deleteJson('/api/v1/marketplace/campaigns/'.$campaign->id.'/bookmark')
            ->assertStatus(401);
        $this->getJson('/api/v1/ambassadors/campaign-bookmarks')
            ->assertStatus(401);
    }

    public function test_business_and_admin_callers_are_rejected(): void
    {
        $campaign = $this->discoverableCampaign();

        foreach ([User::factory()->business()->create(), User::factory()->admin()->create()] as $user) {
            Sanctum::actingAs($user);

            $this->putJson('/api/v1/marketplace/campaigns/'.$campaign->id.'/bookmark')->assertStatus(403);
            $this->deleteJson('/api/v1/marketplace/campaigns/'.$campaign->id.'/bookmark')->assertStatus(403);
            $this->getJson('/api/v1/ambassadors/campaign-bookmarks')->assertStatus(403);
        }

        $this->assertDatabaseCount('campaign_bookmarks', 0);
    }

    public function test_blocked_and_unverified_ambassadors_are_rejected(): void
    {
        $campaign = $this->discoverableCampaign();
        $blocked = User::factory()->ambassador()->create(['status' => AccountStatus::Suspended]);
        $unverified = User::factory()->ambassador()->unverified()->create();

        Sanctum::actingAs($blocked);
        $this->putJson('/api/v1/marketplace/campaigns/'.$campaign->id.'/bookmark')
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);
        $this->getJson('/api/v1/ambassadors/campaign-bookmarks')->assertStatus(403);

        Sanctum::actingAs($unverified);
        $this->putJson('/api/v1/marketplace/campaigns/'.$campaign->id.'/bookmark')
            ->assertStatus(403)
            ->assertJsonPath('error.message', 'Please verify your email address to continue.');

        $this->assertDatabaseCount('campaign_bookmarks', 0);
    }

    #[DataProvider('undiscoverableStatuses')]
    public function test_undiscoverable_campaign_statuses_cannot_be_saved(CampaignStatus $status): void
    {
        $campaign = $this->discoverableCampaign(['status' => $status]);
        Sanctum::actingAs(User::factory()->ambassador()->create());

        $this->putJson('/api/v1/marketplace/campaigns/'.$campaign->id.'/bookmark')
            ->assertStatus(404)
            ->assertJsonPath('error.code', ApiErrorCode::NOT_FOUND);

        $this->assertDatabaseCount('campaign_bookmarks', 0);
    }

    public function test_inactive_owner_and_prohibited_category_cannot_be_saved(): void
    {
        $inactiveOwner = $this->discoverableCampaign();
        $inactiveOwner->user->forceFill(['status' => AccountStatus::Suspended])->save();

        $prohibited = $this->discoverableCampaign();
        $prohibited->category->forceFill([
            'listing_status' => CategoryListingStatus::Prohibited,
        ])->save();

        Sanctum::actingAs(User::factory()->ambassador()->create());

        $this->putJson('/api/v1/marketplace/campaigns/'.$inactiveOwner->id.'/bookmark')->assertStatus(404);
        $this->putJson('/api/v1/marketplace/campaigns/'.$prohibited->id.'/bookmark')->assertStatus(404);

        $this->assertDatabaseCount('campaign_bookmarks', 0);
    }

    public function test_an_ambassador_cannot_see_or_remove_another_ambassadors_bookmark(): void
    {
        $campaign = $this->discoverableCampaign();
        $owner = User::factory()->ambassador()->create();
        $other = User::factory()->ambassador()->create();

        Sanctum::actingAs($owner);
        $this->putJson('/api/v1/marketplace/campaigns/'.$campaign->id.'/bookmark')->assertOk();

        Sanctum::actingAs($other);
        $this->getJson('/api/v1/ambassadors/campaign-bookmarks')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 0)
            ->assertJsonCount(0, 'data');
        $this->deleteJson('/api/v1/marketplace/campaigns/'.$campaign->id.'/bookmark')
            ->assertNoContent();

        $this->assertDatabaseHas('campaign_bookmarks', [
            'ambassador_user_id' => $owner->id,
            'campaign_id' => $campaign->id,
        ]);
    }

    public function test_saved_campaigns_are_paginated_newest_first(): void
    {
        $ambassador = User::factory()->ambassador()->create();
        Sanctum::actingAs($ambassador);

        $first = $this->discoverableCampaign(['title' => 'First saved']);
        $second = $this->discoverableCampaign(['title' => 'Second saved']);
        $third = $this->discoverableCampaign(['title' => 'Third saved']);

        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->putJson('/api/v1/marketplace/campaigns/'.$first->id.'/bookmark')->assertOk();
        Carbon::setTestNow('2026-10-21 11:00:00');
        $this->putJson('/api/v1/marketplace/campaigns/'.$second->id.'/bookmark')->assertOk();
        Carbon::setTestNow('2026-10-21 12:00:00');
        $this->putJson('/api/v1/marketplace/campaigns/'.$third->id.'/bookmark')->assertOk();
        Carbon::setTestNow();

        $this->getJson('/api/v1/ambassadors/campaign-bookmarks?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.pagination.per_page', 2)
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.pagination.current_page', 1)
            ->assertJsonPath('data.0.campaign.id', $third->id)
            ->assertJsonPath('data.0.discoverable', true)
            ->assertJsonPath('data.1.campaign.id', $second->id);

        $this->getJson('/api/v1/ambassadors/campaign-bookmarks?per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('data.0.campaign.id', $first->id)
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/ambassadors/campaign-bookmarks?per_page=101')
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);
    }

    public function test_unavailable_bookmarks_stay_listed_without_unpublished_fields_and_can_be_removed(): void
    {
        $campaign = $this->discoverableCampaign([], [
            'payment_account_identifier' => 'SECRET-ACCOUNT-999',
            'product_description' => 'Secret draft product details',
            'product_name' => 'Public fibre plan',
        ]);
        Sanctum::actingAs(User::factory()->ambassador()->create());
        $this->putJson('/api/v1/marketplace/campaigns/'.$campaign->id.'/bookmark')->assertOk();

        $campaign->forceFill(['status' => CampaignStatus::Expired])->save();

        $response = $this->getJson('/api/v1/ambassadors/campaign-bookmarks')
            ->assertOk()
            ->assertJsonPath('data.0.discoverable', false)
            ->assertJsonPath('data.0.campaign.id', $campaign->id)
            ->assertJsonPath('data.0.campaign.title', $campaign->title)
            ->assertJsonPath('data.0.campaign.status', CampaignStatus::Expired->value)
            ->assertJsonMissingPath('data.0.campaign.product_name')
            ->assertJsonMissingPath('data.0.campaign.product_description')
            ->assertJsonMissingPath('data.0.campaign.price_amount')
            ->assertJsonMissingPath('data.0.campaign.cover_image');

        $this->assertStringNotContainsString('SECRET-ACCOUNT-999', $response->getContent());
        $this->assertStringNotContainsString('Secret draft product details', $response->getContent());

        $this->postJson('/api/v1/deals', ['campaign_id' => $campaign->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ApiErrorCode::BUSINESS_VALIDATION);

        $this->assertDatabaseCount('deals', 0);
        $this->assertDatabaseCount('campaign_bookmarks', 1);

        $this->deleteJson('/api/v1/marketplace/campaigns/'.$campaign->id.'/bookmark')
            ->assertNoContent();
        $this->assertDatabaseCount('campaign_bookmarks', 0);
    }

    public function test_deleting_the_campaign_or_ambassador_removes_the_bookmark(): void
    {
        $campaign = $this->discoverableCampaign();
        $ambassador = User::factory()->ambassador()->create();
        Sanctum::actingAs($ambassador);
        $this->putJson('/api/v1/marketplace/campaigns/'.$campaign->id.'/bookmark')->assertOk();

        $campaign->forceFill(['current_campaign_version_id' => null])->save();
        $campaign->delete();

        $this->assertDatabaseCount('campaign_bookmarks', 0);

        $remaining = $this->discoverableCampaign();
        $this->putJson('/api/v1/marketplace/campaigns/'.$remaining->id.'/bookmark')->assertOk();
        $ambassador->delete();

        $this->assertDatabaseCount('campaign_bookmarks', 0);
    }

    /**
     * @return array<string, array{0: CampaignStatus}>
     */
    public static function undiscoverableStatuses(): array
    {
        return [
            'draft' => [CampaignStatus::Draft],
            'submitted' => [CampaignStatus::Submitted],
            'approved' => [CampaignStatus::Approved],
            'expired' => [CampaignStatus::Expired],
            'closed' => [CampaignStatus::Closed],
            'deactivated' => [CampaignStatus::Deactivated],
            'suspended' => [CampaignStatus::Suspended],
        ];
    }

    /**
     * @param  array<string, mixed>  $campaignAttributes
     * @param  array<string, mixed>  $versionAttributes
     */
    private function discoverableCampaign(array $campaignAttributes = [], array $versionAttributes = []): Campaign
    {
        $owner = User::factory()->business()->create();
        BusinessProfile::factory()->for($owner)->create([
            'legal_name' => 'Ada Ventures Ltd',
            'trading_name' => 'Ada',
            'operating_location' => 'Lagos',
        ]);

        $campaign = Campaign::factory()->for($owner)->create(array_merge([
            'title' => 'Marketplace campaign',
            'status' => CampaignStatus::Active,
            'listing_starts_at' => now(),
            'listing_expires_at' => now()->addDays(30),
        ], $campaignAttributes));

        $version = CampaignVersion::factory()->for($campaign)->published()->create($versionAttributes);
        $campaign->forceFill(['current_campaign_version_id' => $version->id])->save();

        return $campaign->fresh(['category', 'currentVersion', 'user.businessProfile']);
    }
}
