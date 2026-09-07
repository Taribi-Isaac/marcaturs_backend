<?php

namespace Tests\Feature\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\CampaignVersionStatus;
use App\Enums\CommissionType;
use App\Enums\OverallVerificationStatus;
use App\Models\BusinessProfile;
use App\Models\Campaign;
use App\Models\CampaignVersion;
use App\Models\Category;
use App\Models\User;
use App\Models\VerificationRequirement;
use App\Models\VerificationSubmission;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CampaignMarketplaceDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_public_listing_shows_only_active_and_expiring_published_campaigns(): void
    {
        $active = $this->discoverableCampaign(['title' => 'Visible fibre campaign']);
        $expiring = $this->discoverableCampaign([
            'title' => 'Expiring solar campaign',
            'status' => CampaignStatus::Expiring,
        ]);

        foreach ([
            CampaignStatus::Draft,
            CampaignStatus::Submitted,
            CampaignStatus::Approved,
            CampaignStatus::Deactivated,
            CampaignStatus::Suspended,
            CampaignStatus::Expired,
            CampaignStatus::Closed,
        ] as $status) {
            $this->discoverableCampaign([
                'title' => 'Hidden '.$status->value,
                'status' => $status,
            ]);
        }

        $this->getJson('/api/v1/marketplace/campaigns')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('data.0.title', 'Expiring solar campaign')
            ->assertJsonPath('data.1.title', 'Visible fibre campaign')
            ->assertJsonMissingPath('data.0.review_reason')
            ->assertJsonMissingPath('data.0.payment_account_identifier')
            ->assertJsonMissingPath('data.0.user')
            ->assertJsonPath('data.0.is_featured', false);

        $this->getJson('/api/v1/marketplace/campaigns/'.$active->id)
            ->assertOk()
            ->assertJsonPath('data.status', CampaignStatus::Active->value)
            ->assertJsonPath('data.version_number', 1)
            ->assertJsonPath('data.payment_provider', 'Example Bank')
            ->assertJsonMissingPath('data.payment_account_identifier')
            ->assertJsonMissingPath('data.payment_instructions')
            ->assertJsonMissingPath('data.payment_contact')
            ->assertJsonMissingPath('data.review_reason');

        $this->getJson('/api/v1/marketplace/campaigns/'.$expiring->id)
            ->assertOk()
            ->assertJsonPath('data.status', CampaignStatus::Expiring->value);
    }

    public function test_undiscoverable_campaigns_are_not_found_by_id(): void
    {
        $draft = $this->discoverableCampaign([
            'status' => CampaignStatus::Draft,
        ]);

        $this->getJson('/api/v1/marketplace/campaigns/'.$draft->id)
            ->assertStatus(404)
            ->assertJsonPath('error.code', ApiErrorCode::NOT_FOUND);

        $this->getJson('/api/v1/marketplace/campaigns/999999')
            ->assertStatus(404);
    }

    public function test_draft_version_terms_are_not_exposed(): void
    {
        $campaign = $this->discoverableCampaign([], [
            'product_name' => 'Published kit',
            'commission_rate' => '10.00',
        ]);

        CampaignVersion::factory()->for($campaign)->create([
            'version_number' => 2,
            'status' => CampaignVersionStatus::Draft,
            'product_name' => 'Secret draft product',
            'commission_rate' => '90.00',
        ]);

        $this->getJson('/api/v1/marketplace/campaigns/'.$campaign->id)
            ->assertOk()
            ->assertJsonPath('data.product_name', 'Published kit')
            ->assertJsonPath('data.commission_rate', '10.00');

        $this->assertStringNotContainsString('Secret draft product', $this->getJson('/api/v1/marketplace/campaigns/'.$campaign->id)->getContent());
    }

    public function test_keyword_search_filters_and_does_not_search_private_payment_fields(): void
    {
        $this->discoverableCampaign(['title' => 'Classroom internet package'], [
            'product_name' => 'Fibre 50Mbps',
            'service_area' => 'Lagos',
            'payment_account_identifier' => 'SECRETACC999',
        ]);
        $this->discoverableCampaign(['title' => 'Solar starter'], [
            'product_name' => 'Panel pack',
            'service_area' => 'Abuja',
            'commission_type' => CommissionType::Fixed,
        ]);
        Campaign::query()->where('title', 'Solar starter')->first()?->user?->businessProfile
            ?->update(['operating_location' => 'Abuja']);

        $this->getJson('/api/v1/marketplace/campaigns?q=Lagos')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.title', 'Classroom internet package');

        $this->getJson('/api/v1/marketplace/campaigns?q=SECRETACC999')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 0);

        $this->getJson('/api/v1/marketplace/campaigns?commission_type=fixed')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.title', 'Solar starter');

        $this->getJson('/api/v1/marketplace/campaigns?service_area=Abuja')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1);

        $this->getJson('/api/v1/marketplace/campaigns?status=active')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 2);

        $this->getJson('/api/v1/marketplace/campaigns?status=draft')
            ->assertStatus(400);
    }

    public function test_category_and_price_filters_respect_taxonomy_rules(): void
    {
        $allowed = Category::factory()->create(['name' => 'Technology', 'slug' => 'technology']);
        $restricted = Category::factory()->restricted()->create(['name' => 'Real Estate', 'slug' => 'real-estate']);
        $prohibited = Category::factory()->prohibited()->create(['name' => 'Hidden Cat', 'slug' => 'hidden-cat']);
        $inactive = Category::factory()->create(['name' => 'Old', 'slug' => 'old', 'is_active' => false]);

        $visible = $this->discoverableCampaign(['category_id' => $allowed->id], ['price_amount' => '10000.00']);
        $this->discoverableCampaign(['category_id' => $restricted->id], ['price_amount' => '20000.00']);
        $this->discoverableCampaign(['category_id' => $prohibited->id], ['price_amount' => '30000.00']);
        $this->discoverableCampaign(['category_id' => $inactive->id], ['price_amount' => '40000.00']);

        $this->getJson('/api/v1/marketplace/campaigns')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 2);

        $this->getJson('/api/v1/marketplace/campaigns?category_id='.$allowed->id)
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $visible->id);

        $this->getJson('/api/v1/marketplace/campaigns?price_min=15000&price_max=25000')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.category.listing_status', 'restricted');
    }

    public function test_verified_filter_uses_business_verification_status(): void
    {
        $verifiedCampaign = $this->discoverableCampaign(['title' => 'Verified biz campaign']);
        $requirement = VerificationRequirement::factory()->create();
        VerificationSubmission::factory()
            ->for($verifiedCampaign->user)
            ->for($requirement, 'requirement')
            ->approved()
            ->create();

        $this->discoverableCampaign(['title' => 'Unverified biz campaign']);

        $this->getJson('/api/v1/marketplace/campaigns/'.$verifiedCampaign->id)
            ->assertOk()
            ->assertJsonPath('data.business.verification_status', OverallVerificationStatus::Verified->value);

        $this->getJson('/api/v1/marketplace/campaigns?verified=1')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.title', 'Verified biz campaign');
    }

    public function test_pagination_caps_page_size_and_newest_are_first(): void
    {
        Carbon::setTestNow('2026-09-02 12:00:00');
        $this->discoverableCampaign(['listing_starts_at' => now()->subDay()]);
        Carbon::setTestNow('2026-09-02 13:00:00');
        $newer = $this->discoverableCampaign(['listing_starts_at' => now()]);

        $this->getJson('/api/v1/marketplace/campaigns')
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('meta.pagination.per_page', 15);

        for ($i = 0; $i < 16; $i++) {
            $this->discoverableCampaign(['title' => 'Page filler '.$i]);
        }

        $this->getJson('/api/v1/marketplace/campaigns')
            ->assertOk()
            ->assertJsonPath('meta.pagination.per_page', 15)
            ->assertJsonPath('meta.pagination.total', 18)
            ->assertJsonPath('meta.pagination.last_page', 2);

        $this->getJson('/api/v1/marketplace/campaigns?per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.pagination.per_page', 100);

        $this->getJson('/api/v1/marketplace/campaigns?per_page=101')
            ->assertStatus(400);
    }

    public function test_authenticated_ambassador_can_browse_and_owner_routes_remain_private(): void
    {
        $campaign = $this->discoverableCampaign();
        Sanctum::actingAs(User::factory()->ambassador()->create());

        $this->getJson('/api/v1/marketplace/campaigns')
            ->assertOk()
            ->assertJsonPath('data.0.id', $campaign->id);

        $this->getJson('/api/v1/campaigns')
            ->assertStatus(403);
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

        $version = CampaignVersion::factory()->for($campaign)->published()->create(array_merge([
            'service_area' => 'Lagos',
        ], $versionAttributes));

        $campaign->forceFill(['current_campaign_version_id' => $version->id])->save();

        return $campaign->fresh(['category', 'currentVersion', 'user.businessProfile']);
    }
}
