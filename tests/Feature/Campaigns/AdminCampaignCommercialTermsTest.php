<?php

namespace Tests\Feature\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\CampaignVersionStatus;
use App\Enums\CommissionTrigger;
use App\Enums\CommissionType;
use App\Enums\PricingMethod;
use App\Models\BusinessProfile;
use App\Models\Campaign;
use App\Models\CampaignVersion;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCampaignCommercialTermsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_show_campaign_with_published_commercial_terms(): void
    {
        [$campaign, $version] = $this->campaignWithPublishedVersion([
            'product_name' => 'Solar home kit',
            'product_description' => 'Complete rooftop package.',
            'pricing_method' => PricingMethod::Fixed,
            'price_amount' => '125000.00',
            'price_currency' => 'NGN',
            'service_area' => 'Lagos',
            'commission_type' => CommissionType::Percentage,
            'commission_rate' => '12.50',
            'commission_amount' => null,
            'commission_trigger' => CommissionTrigger::PaymentConfirmation,
            'commission_trigger_description' => null,
            'commission_payment_deadline_days' => 14,
            'minimum_qualifying_amount' => '10000.00',
            'qualifying_conditions' => 'Customer must complete installation.',
            'refund_cancellation_rules' => 'Refunds within 7 days.',
            'approved_claims' => 'Certified installers only.',
            'prohibited_claims' => 'No guaranteed ROI claims.',
            'brand_use_rules' => 'Use approved brand kit.',
            'geographic_customer_restrictions' => 'Nigeria only.',
            'approved_copy' => 'Approved ambassador copy.',
            'marketing_links' => ['https://example.test/kit'],
            'payment_destination_name' => 'Ada Solar Ventures Ltd',
            'payment_provider' => 'Demo Bank',
            'payment_account_identifier' => 'SECRETACC123',
            'payment_instructions' => 'Transfer to business account only.',
            'payment_contact' => 'payments@secret.test',
            'terms' => 'Official campaign participation terms.',
        ]);

        Sanctum::actingAs(User::factory()->admin()->create());
        $response = $this->getJson('/api/v1/admin/campaigns/'.$campaign->id)
            ->assertOk()
            ->assertJsonPath('data.id', $campaign->id)
            ->assertJsonPath('data.current_version.id', $version->id)
            ->assertJsonPath('data.current_version.version_number', 1)
            ->assertJsonPath('data.current_version.status', CampaignVersionStatus::Published->value)
            ->assertJsonPath('data.current_version.product_name', 'Solar home kit')
            ->assertJsonPath('data.current_version.product_description', 'Complete rooftop package.')
            ->assertJsonPath('data.current_version.pricing_method', PricingMethod::Fixed->value)
            ->assertJsonPath('data.current_version.price_amount', '125000.00')
            ->assertJsonPath('data.current_version.price_currency', 'NGN')
            ->assertJsonPath('data.current_version.service_area', 'Lagos')
            ->assertJsonPath('data.current_version.commission_type', CommissionType::Percentage->value)
            ->assertJsonPath('data.current_version.commission_rate', '12.50')
            ->assertJsonPath('data.current_version.commission_amount', null)
            ->assertJsonPath('data.current_version.commission_trigger', CommissionTrigger::PaymentConfirmation->value)
            ->assertJsonPath('data.current_version.commission_trigger_description', null)
            ->assertJsonPath('data.current_version.commission_payment_deadline_days', 14)
            ->assertJsonPath('data.current_version.minimum_qualifying_amount', '10000.00')
            ->assertJsonPath('data.current_version.qualifying_conditions', 'Customer must complete installation.')
            ->assertJsonPath('data.current_version.refund_cancellation_rules', 'Refunds within 7 days.')
            ->assertJsonPath('data.current_version.approved_claims', 'Certified installers only.')
            ->assertJsonPath('data.current_version.prohibited_claims', 'No guaranteed ROI claims.')
            ->assertJsonPath('data.current_version.brand_use_rules', 'Use approved brand kit.')
            ->assertJsonPath('data.current_version.geographic_customer_restrictions', 'Nigeria only.')
            ->assertJsonPath('data.current_version.approved_copy', 'Approved ambassador copy.')
            ->assertJsonPath('data.current_version.marketing_links.0', 'https://example.test/kit')
            ->assertJsonPath('data.current_version.payment_destination_name', 'Ada Solar Ventures Ltd')
            ->assertJsonPath('data.current_version.payment_provider', 'Demo Bank')
            ->assertJsonPath('data.current_version.terms', 'Official campaign participation terms.')
            ->assertJsonMissingPath('data.current_version.payment_account_identifier')
            ->assertJsonMissingPath('data.current_version.payment_instructions')
            ->assertJsonMissingPath('data.current_version.payment_contact');

        $this->assertNotNull($response->json('data.current_version.published_at'));
    }

    public function test_business_cannot_access_admin_campaign_detail(): void
    {
        [$campaign] = $this->campaignWithPublishedVersion();

        Sanctum::actingAs($campaign->user);
        $this->getJson('/api/v1/admin/campaigns/'.$campaign->id)
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);
    }

    public function test_ambassador_cannot_access_admin_campaign_detail(): void
    {
        [$campaign] = $this->campaignWithPublishedVersion();

        Sanctum::actingAs(User::factory()->ambassador()->create());
        $this->getJson('/api/v1/admin/campaigns/'.$campaign->id)
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);
    }

    public function test_unauthenticated_admin_campaign_detail_is_rejected(): void
    {
        [$campaign] = $this->campaignWithPublishedVersion();

        $this->getJson('/api/v1/admin/campaigns/'.$campaign->id)
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);
    }

    public function test_unpublished_draft_commercial_terms_are_not_leaked_as_current_version(): void
    {
        $owner = User::factory()->business()->create();
        BusinessProfile::factory()->for($owner)->create();
        $campaign = Campaign::factory()->for($owner)->create(['title' => 'Draft only campaign']);

        $published = CampaignVersion::factory()->for($campaign)->published()->create([
            'version_number' => 1,
            'product_name' => 'Published solar kit',
            'price_amount' => '50000.00',
            'payment_account_identifier' => 'PUBLISHEDACC',
        ]);
        $campaign->forceFill(['current_campaign_version_id' => $published->id])->save();

        CampaignVersion::factory()->for($campaign)->create([
            'version_number' => 2,
            'status' => CampaignVersionStatus::Draft,
            'product_name' => 'SECRET DRAFT PRODUCT',
            'price_amount' => '999999.00',
            'commission_rate' => '99.00',
            'payment_account_identifier' => 'DRAFTSECRETACC',
            'payment_instructions' => 'Draft-only payment instructions.',
            'terms' => 'Draft-only terms that must not appear.',
        ]);

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/v1/admin/campaigns/'.$campaign->id)
            ->assertOk()
            ->assertJsonPath('data.current_version.id', $published->id)
            ->assertJsonPath('data.current_version.version_number', 1)
            ->assertJsonPath('data.current_version.status', CampaignVersionStatus::Published->value)
            ->assertJsonPath('data.current_version.product_name', 'Published solar kit')
            ->assertJsonPath('data.current_version.price_amount', '50000.00')
            ->assertJsonMissingPath('data.current_version.payment_account_identifier');

        $encoded = json_encode($this->getJson('/api/v1/admin/campaigns/'.$campaign->id)->json('data'));
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('SECRET DRAFT PRODUCT', $encoded);
        $this->assertStringNotContainsString('DRAFTSECRETACC', $encoded);
        $this->assertStringNotContainsString('Draft-only terms', $encoded);
        $this->assertStringNotContainsString('999999.00', $encoded);
    }

    public function test_draft_current_version_exposes_identity_without_commercial_terms(): void
    {
        $owner = User::factory()->business()->create();
        BusinessProfile::factory()->for($owner)->create();
        $campaign = Campaign::factory()->for($owner)->create();
        $draft = CampaignVersion::factory()->for($campaign)->create([
            'version_number' => 1,
            'status' => CampaignVersionStatus::Draft,
            'product_name' => 'Unpublished draft terms',
            'payment_account_identifier' => 'DRAFTONLYACC',
        ]);
        $campaign->forceFill(['current_campaign_version_id' => $draft->id])->save();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/v1/admin/campaigns/'.$campaign->id)
            ->assertOk()
            ->assertJsonPath('data.current_version.id', $draft->id)
            ->assertJsonPath('data.current_version.version_number', 1)
            ->assertJsonPath('data.current_version.status', CampaignVersionStatus::Draft->value)
            ->assertJsonMissingPath('data.current_version.product_name')
            ->assertJsonMissingPath('data.current_version.price_amount')
            ->assertJsonMissingPath('data.current_version.payment_destination_name')
            ->assertJsonMissingPath('data.current_version.payment_account_identifier')
            ->assertJsonMissingPath('data.current_version.payment_instructions')
            ->assertJsonMissingPath('data.current_version.payment_contact')
            ->assertJsonMissingPath('data.current_version.terms');
    }

    public function test_campaign_without_current_version_returns_null_current_version(): void
    {
        $owner = User::factory()->business()->create();
        BusinessProfile::factory()->for($owner)->create();
        $campaign = Campaign::factory()->for($owner)->create([
            'current_campaign_version_id' => null,
        ]);

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/v1/admin/campaigns/'.$campaign->id)
            ->assertOk()
            ->assertJsonPath('data.current_version', null);
    }

    public function test_admin_detail_remains_valid_across_lifecycle_states_without_activating(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        foreach ([
            CampaignStatus::Draft,
            CampaignStatus::Submitted,
            CampaignStatus::Approved,
            CampaignStatus::Active,
            CampaignStatus::Expiring,
            CampaignStatus::Expired,
            CampaignStatus::Deactivated,
            CampaignStatus::Suspended,
            CampaignStatus::Closed,
        ] as $status) {
            [$campaign] = $this->campaignWithPublishedVersion([
                'product_name' => 'Lifecycle '.$status->value,
            ], $status);

            $this->getJson('/api/v1/admin/campaigns/'.$campaign->id)
                ->assertOk()
                ->assertJsonPath('data.status', $status->value)
                ->assertJsonPath('data.current_version.status', CampaignVersionStatus::Published->value)
                ->assertJsonPath('data.current_version.product_name', 'Lifecycle '.$status->value);

            $fresh = $campaign->fresh();
            $this->assertSame($status, $fresh->status);
            if ($status !== CampaignStatus::Active) {
                $this->assertNotSame(CampaignStatus::Active, $fresh->status);
            }
        }
    }

    public function test_admin_list_does_not_expand_commercial_terms(): void
    {
        [$campaign] = $this->campaignWithPublishedVersion([
            'product_name' => 'List should stay summary',
            'payment_account_identifier' => 'LISTSECRETACC',
        ], CampaignStatus::Submitted);

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/v1/admin/campaigns?status=submitted')
            ->assertOk()
            ->assertJsonPath('data.0.id', $campaign->id)
            ->assertJsonPath('data.0.current_version.version_number', 1)
            ->assertJsonPath('data.0.current_version.status', CampaignVersionStatus::Published->value)
            ->assertJsonMissingPath('data.0.current_version.product_name')
            ->assertJsonMissingPath('data.0.current_version.payment_account_identifier');
    }

    /**
     * @param  array<string, mixed>  $versionAttributes
     * @return array{0: Campaign, 1: CampaignVersion}
     */
    private function campaignWithPublishedVersion(
        array $versionAttributes = [],
        CampaignStatus $status = CampaignStatus::Submitted,
    ): array {
        $owner = User::factory()->business()->create();
        BusinessProfile::factory()->for($owner)->create();
        $campaign = Campaign::factory()->for($owner)->create([
            'status' => $status,
            'title' => 'Admin commercial terms campaign',
        ]);

        $version = CampaignVersion::factory()->for($campaign)->published()->create(array_merge([
            'version_number' => 1,
            'product_name' => 'Default published product',
            'payment_account_identifier' => '0000000000',
            'payment_instructions' => 'Pay the business directly.',
            'payment_contact' => 'billing@example.test',
        ], $versionAttributes));

        $campaign->forceFill(['current_campaign_version_id' => $version->id])->save();

        return [$campaign->fresh(['user', 'currentVersion']), $version->fresh()];
    }
}
