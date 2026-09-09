<?php

namespace Tests\Feature\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\CampaignVersionStatus;
use App\Models\BusinessProfile;
use App\Models\Campaign;
use App\Models\CampaignVersion;
use App\Models\Deal;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use App\Support\Campaigns\OfficialPaymentShare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OfficialPaymentInformationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_access_returns_payment_destination_for_discoverable_campaign(): void
    {
        $campaign = $this->discoverableCampaign([], [
            'payment_destination_name' => 'Ada Solar Ventures',
            'payment_provider' => 'Demo Bank',
            'payment_account_identifier' => '0123456789',
            'payment_instructions' => 'Pay Ada Solar directly. MarcatursHub does not receive this payment.',
            'payment_contact' => 'payments@example.test',
        ]);
        $token = OfficialPaymentShare::ensureToken($campaign);

        $this->getJson('/api/v1/public/official-payment-information/'.$token)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.financial_boundary.platform_holds_customer_funds', false)
            ->assertJsonPath('data.financial_boundary.customer_pays', 'business')
            ->assertJsonPath('data.business.trading_name', 'Ada')
            ->assertJsonPath('data.campaign.title', 'Marketplace campaign')
            ->assertJsonPath('data.campaign.status', CampaignStatus::Active->value)
            ->assertJsonPath('data.campaign_version.version_number', 1)
            ->assertJsonPath('data.campaign_version.product_name', 'Example product')
            ->assertJsonPath('data.payment_destination.destination_name', 'Ada Solar Ventures')
            ->assertJsonPath('data.payment_destination.provider', 'Demo Bank')
            ->assertJsonPath('data.payment_destination.account_identifier', '0123456789')
            ->assertJsonPath(
                'data.payment_destination.instructions',
                'Pay Ada Solar directly. MarcatursHub does not receive this payment.',
            )
            ->assertJsonPath('data.payment_destination.contact', 'payments@example.test')
            ->assertJsonPath('data.share.token', $token)
            ->assertJsonPath('data.share.path', '/api/v1/public/official-payment-information/'.$token)
            ->assertJsonMissingPath('data.payment_account_identifier')
            ->assertJsonMissingPath('data.user')
            ->assertJsonMissingPath('data.review_reason')
            ->assertJsonMissingPath('data.storage_path')
            ->assertJsonMissingPath('data.current_campaign_version_id');
    }

    public function test_business_and_ambassador_authenticated_access_also_succeeds(): void
    {
        $campaign = $this->discoverableCampaign();
        $token = OfficialPaymentShare::ensureToken($campaign);

        Sanctum::actingAs($campaign->user);
        $this->getJson('/api/v1/public/official-payment-information/'.$token)
            ->assertOk()
            ->assertJsonPath('data.campaign.id', $campaign->id);

        Sanctum::actingAs(User::factory()->ambassador()->create());
        $this->getJson('/api/v1/public/official-payment-information/'.$token)
            ->assertOk()
            ->assertJsonPath('data.share.token', $token);
    }

    public function test_expiring_campaign_is_eligible(): void
    {
        $campaign = $this->discoverableCampaign(['status' => CampaignStatus::Expiring]);
        $token = OfficialPaymentShare::ensureToken($campaign);

        $this->getJson('/api/v1/public/official-payment-information/'.$token)
            ->assertOk()
            ->assertJsonPath('data.campaign.status', CampaignStatus::Expiring->value);
    }

    #[DataProvider('ineligibleStatuses')]
    public function test_ineligible_campaign_statuses_do_not_expose_payment_information(CampaignStatus $status): void
    {
        $campaign = $this->discoverableCampaign(['status' => $status]);
        $token = OfficialPaymentShare::ensureToken($campaign);

        $this->getJson('/api/v1/public/official-payment-information/'.$token)
            ->assertStatus(404)
            ->assertJsonPath('error.code', ApiErrorCode::NOT_FOUND)
            ->assertJsonMissingPath('data.payment_destination');
    }

    /**
     * @return array<string, array{0: CampaignStatus}>
     */
    public static function ineligibleStatuses(): array
    {
        return [
            'draft' => [CampaignStatus::Draft],
            'submitted' => [CampaignStatus::Submitted],
            'approved' => [CampaignStatus::Approved],
            'expired' => [CampaignStatus::Expired],
            'deactivated' => [CampaignStatus::Deactivated],
            'suspended' => [CampaignStatus::Suspended],
            'closed' => [CampaignStatus::Closed],
        ];
    }

    public function test_invalid_and_unknown_tokens_return_same_not_found_shape(): void
    {
        $this->getJson('/api/v1/public/official-payment-information/not-a-real-token-aaaaaaaaaaaaaaaaaaaaaaaa')
            ->assertStatus(404)
            ->assertJsonPath('error.code', ApiErrorCode::NOT_FOUND);

        $this->getJson('/api/v1/public/official-payment-information/short')
            ->assertStatus(404)
            ->assertJsonPath('error.code', ApiErrorCode::NOT_FOUND);
    }

    public function test_new_published_version_is_reflected_and_deal_snapshots_remain_untouched(): void
    {
        $campaign = $this->discoverableCampaign([], [
            'product_name' => 'Version One Kit',
            'payment_account_identifier' => '1111111111',
            'payment_instructions' => 'Pay using V1 account.',
        ]);
        $token = OfficialPaymentShare::ensureToken($campaign);
        $v1 = $campaign->currentVersion;
        $this->assertNotNull($v1);

        $ambassador = User::factory()->ambassador()->create();
        $deal = Deal::factory()->create([
            'campaign_id' => $campaign->id,
            'campaign_version_id' => $v1->id,
            'business_user_id' => $campaign->user_id,
            'ambassador_user_id' => $ambassador->id,
            'product_name' => $v1->product_name,
            'price_amount' => $v1->price_amount,
            'commission_rate' => $v1->commission_rate,
        ]);
        $originalProduct = $deal->product_name;
        $originalPrice = (string) $deal->price_amount;

        $v2 = CampaignVersion::factory()->for($campaign)->published()->create([
            'version_number' => 2,
            'product_name' => 'Version Two Kit',
            'payment_destination_name' => 'Ada Solar Ventures V2',
            'payment_provider' => 'New Bank',
            'payment_account_identifier' => '9999999999',
            'payment_instructions' => 'Pay using V2 account.',
            'payment_contact' => 'v2@example.test',
        ]);
        $campaign->forceFill(['current_campaign_version_id' => $v2->id])->save();

        $this->getJson('/api/v1/public/official-payment-information/'.$token)
            ->assertOk()
            ->assertJsonPath('data.campaign_version.version_number', 2)
            ->assertJsonPath('data.campaign_version.product_name', 'Version Two Kit')
            ->assertJsonPath('data.payment_destination.account_identifier', '9999999999')
            ->assertJsonPath('data.payment_destination.instructions', 'Pay using V2 account.');

        $deal->refresh();
        $this->assertSame($originalProduct, $deal->product_name);
        $this->assertSame($originalPrice, (string) $deal->price_amount);
        $this->assertSame($v1->id, $deal->campaign_version_id);
        $this->assertSame(CampaignVersionStatus::Published, $v1->fresh()->status);
    }

    public function test_marketplace_detail_exposes_share_reference_but_not_account_fields(): void
    {
        $campaign = $this->discoverableCampaign([], [
            'payment_account_identifier' => 'SHOULD-NOT-LEAK',
            'payment_instructions' => 'SECRET INSTRUCTIONS',
            'payment_contact' => 'secret@example.test',
        ]);

        $this->getJson('/api/v1/marketplace/campaigns/'.$campaign->id)
            ->assertOk()
            ->assertJsonPath('data.payment_provider', 'Example Bank')
            ->assertJsonPath('data.official_payment.token', $campaign->fresh()->official_payment_token)
            ->assertJsonMissingPath('data.payment_account_identifier')
            ->assertJsonMissingPath('data.payment_instructions')
            ->assertJsonMissingPath('data.payment_contact')
            ->assertJsonMissingPath('data.official_payment.account_identifier');
    }

    public function test_business_campaign_show_includes_official_payment_share_block(): void
    {
        $campaign = $this->discoverableCampaign();
        Sanctum::actingAs($campaign->user);

        $this->getJson('/api/v1/campaigns/'.$campaign->id)
            ->assertOk()
            ->assertJsonPath('data.official_payment.path', '/api/v1/public/official-payment-information/'.$campaign->fresh()->official_payment_token);
    }

    public function test_rate_limiter_is_enforced(): void
    {
        config(['api.rate_limits.official_payment_per_minute' => 1]);
        RateLimiter::clear('official-payment');

        $campaign = $this->discoverableCampaign();
        $token = OfficialPaymentShare::ensureToken($campaign);

        $this->getJson('/api/v1/public/official-payment-information/'.$token)->assertOk();
        $this->getJson('/api/v1/public/official-payment-information/'.$token)
            ->assertStatus(429)
            ->assertJsonPath('error.code', ApiErrorCode::RATE_LIMITED);
    }

    public function test_version_publish_assigns_stable_token(): void
    {
        $owner = User::factory()->business()->create();
        BusinessProfile::factory()->for($owner)->create();
        $campaign = Campaign::factory()->for($owner)->create(['status' => CampaignStatus::Draft]);
        $version = CampaignVersion::factory()->for($campaign)->create([
            'status' => CampaignVersionStatus::Draft,
        ]);

        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions/'.$version->version_number.'/publish')
            ->assertOk();

        $campaign->refresh();
        $this->assertNotNull($campaign->official_payment_token);
        $this->assertSame(48, strlen((string) $campaign->official_payment_token));

        $first = $campaign->official_payment_token;
        $v2 = CampaignVersion::factory()->for($campaign)->create([
            'version_number' => 2,
            'status' => CampaignVersionStatus::Draft,
        ]);
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions/2/publish')->assertOk();
        $this->assertSame($first, $campaign->fresh()->official_payment_token);
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
