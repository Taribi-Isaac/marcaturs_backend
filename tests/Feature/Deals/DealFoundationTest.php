<?php

namespace Tests\Feature\Deals;

use App\Enums\AccountStatus;
use App\Enums\CampaignStatus;
use App\Enums\CommissionType;
use App\Enums\DealEventType;
use App\Enums\DealStatus;
use App\Models\Campaign;
use App\Models\CampaignVersion;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DealFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ambassador_creates_a_deal_against_an_active_campaign(): void
    {
        $campaign = $this->eligibleCampaign();
        $ambassador = User::factory()->ambassador()->create();
        Sanctum::actingAs($ambassador);

        $response = $this->postJson('/api/v1/deals', [
            'campaign_id' => $campaign->id,
            'expected_transaction_amount' => 100000,
        ])->assertCreated()
            ->assertJsonPath('data.status', DealStatus::PaymentPending->value)
            ->assertJsonPath('data.business.id', $campaign->user_id)
            ->assertJsonPath('data.ambassador.id', $ambassador->id)
            ->assertJsonPath('data.campaign.id', $campaign->id)
            ->assertJsonPath('data.campaign_version.id', $campaign->current_campaign_version_id)
            ->assertJsonPath('data.commission_type', CommissionType::Percentage->value)
            ->assertJsonPath('data.commission_rate', '10.00')
            ->assertJsonPath('data.commission_trigger', 'payment_confirmation')
            ->assertJsonPath('data.commission_payment_deadline_days', 7)
            ->assertJsonPath('data.expected_transaction_amount', '100000.00')
            ->assertJsonPath('data.events.0.type', DealEventType::Created->value)
            ->assertJsonPath('data.events.0.actor.id', $ambassador->id)
            ->assertJsonPath('data.events.0.new_status', DealStatus::PaymentPending->value)
            ->assertJsonMissingPath('data.payment_account_identifier')
            ->assertJsonMissingPath('data.customer_user_id')
            ->assertJsonMissingPath('data.conversation_id');

        $this->assertSame($campaign->user_id, Deal::query()->findOrFail($response->json('data.id'))->business_user_id);
        $this->assertDatabaseCount('deal_events', 1);
    }

    public function test_expiring_campaign_can_receive_a_new_deal(): void
    {
        $campaign = $this->eligibleCampaign(['status' => CampaignStatus::Expiring]);
        Sanctum::actingAs(User::factory()->ambassador()->create());

        $this->postJson('/api/v1/deals', ['campaign_id' => $campaign->id])
            ->assertCreated()
            ->assertJsonPath('data.campaign.status', CampaignStatus::Expiring->value);
    }

    #[DataProvider('ineligibleCampaignStatuses')]
    public function test_ineligible_campaign_statuses_cannot_receive_new_deals(CampaignStatus $status): void
    {
        $campaign = $this->eligibleCampaign(['status' => $status]);
        Sanctum::actingAs(User::factory()->ambassador()->create());

        $this->postJson('/api/v1/deals', ['campaign_id' => $campaign->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ApiErrorCode::BUSINESS_VALIDATION);

        $this->assertDatabaseCount('deals', 0);
        $this->assertDatabaseCount('deal_events', 0);
    }

    /**
     * @return array<string, array{0: CampaignStatus}>
     */
    public static function ineligibleCampaignStatuses(): array
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

    public function test_creation_fails_without_a_published_current_version(): void
    {
        $campaign = $this->eligibleCampaign();
        $campaign->forceFill(['current_campaign_version_id' => null])->save();
        Sanctum::actingAs(User::factory()->ambassador()->create());

        $this->postJson('/api/v1/deals', ['campaign_id' => $campaign->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ApiErrorCode::BUSINESS_VALIDATION);

        $this->assertDatabaseCount('deals', 0);
    }

    public function test_client_cannot_choose_version_or_commission_fields(): void
    {
        $campaign = $this->eligibleCampaign();
        Sanctum::actingAs(User::factory()->ambassador()->create());

        $this->postJson('/api/v1/deals', [
            'campaign_id' => $campaign->id,
            'campaign_version_id' => 999,
            'commission_rate' => 99,
            'business_id' => 1,
        ])->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);
    }

    public function test_deal_keeps_bound_version_after_a_newer_version_is_published(): void
    {
        $campaign = $this->eligibleCampaign();
        $originalVersionId = $campaign->current_campaign_version_id;
        $ambassador = User::factory()->ambassador()->create();
        Sanctum::actingAs($ambassador);

        $dealId = $this->postJson('/api/v1/deals', ['campaign_id' => $campaign->id])
            ->assertCreated()
            ->json('data.id');

        $newer = CampaignVersion::factory()->for($campaign)->published()->create([
            'version_number' => 2,
            'commission_rate' => '50.00',
            'product_name' => 'Updated product',
        ]);
        $campaign->forceFill(['current_campaign_version_id' => $newer->id])->save();

        $this->getJson('/api/v1/deals/'.$dealId)
            ->assertOk()
            ->assertJsonPath('data.campaign_version.id', $originalVersionId)
            ->assertJsonPath('data.commission_rate', '10.00')
            ->assertJsonPath('data.product_name', 'Example product');
    }

    public function test_same_ambassador_can_create_multiple_deals_on_the_same_version(): void
    {
        $campaign = $this->eligibleCampaign();
        $ambassador = User::factory()->ambassador()->create();
        Sanctum::actingAs($ambassador);

        $this->postJson('/api/v1/deals', ['campaign_id' => $campaign->id])->assertCreated();
        $this->postJson('/api/v1/deals', ['campaign_id' => $campaign->id])->assertCreated();

        $this->assertDatabaseCount('deals', 2);
        $this->assertSame(2, Deal::query()->where('campaign_version_id', $campaign->current_campaign_version_id)->count());
    }

    public function test_business_and_admin_cannot_create_deals(): void
    {
        $campaign = $this->eligibleCampaign();

        Sanctum::actingAs($campaign->user);
        $this->postJson('/api/v1/deals', ['campaign_id' => $campaign->id])->assertStatus(403);

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson('/api/v1/deals', ['campaign_id' => $campaign->id])->assertStatus(403);

        $this->assertDatabaseCount('deals', 0);
    }

    public function test_participants_list_and_show_are_scoped_and_idor_is_404(): void
    {
        $campaignA = $this->eligibleCampaign();
        $campaignB = $this->eligibleCampaign();
        $ambassadorA = User::factory()->ambassador()->create();
        $ambassadorB = User::factory()->ambassador()->create();

        Sanctum::actingAs($ambassadorA);
        $dealA = $this->postJson('/api/v1/deals', ['campaign_id' => $campaignA->id])->json('data.id');

        Sanctum::actingAs($ambassadorB);
        $dealB = $this->postJson('/api/v1/deals', ['campaign_id' => $campaignB->id])->json('data.id');

        Sanctum::actingAs($ambassadorA);
        $this->getJson('/api/v1/deals')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $dealA)
            ->assertJsonMissingPath('data.0.events');
        $this->getJson('/api/v1/deals/'.$dealA)->assertOk();
        $this->getJson('/api/v1/deals/'.$dealB)->assertStatus(404);

        Sanctum::actingAs($campaignA->user);
        $this->getJson('/api/v1/deals')
            ->assertOk()
            ->assertJsonPath('data.0.id', $dealA);
        $this->getJson('/api/v1/deals/'.$dealB)->assertStatus(404);

        Sanctum::actingAs($campaignB->user);
        $this->getJson('/api/v1/deals/'.$dealA)->assertStatus(404);

        $this->patchJson('/api/v1/deals/'.$dealA, ['commission_rate' => 1])->assertStatus(405);
    }

    public function test_guest_restricted_and_banned_accounts_cannot_use_deals(): void
    {
        $campaign = $this->eligibleCampaign();
        $ambassador = User::factory()->ambassador()->create();
        Sanctum::actingAs($ambassador);
        $dealId = $this->postJson('/api/v1/deals', ['campaign_id' => $campaign->id])->json('data.id');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/deals')->assertStatus(401);

        Sanctum::actingAs($ambassador);
        $ambassador->forceFill(['status' => AccountStatus::Restricted])->save();
        $this->getJson('/api/v1/deals')->assertStatus(403);
        $this->postJson('/api/v1/deals', ['campaign_id' => $campaign->id])->assertStatus(403);

        $ambassador->forceFill(['status' => AccountStatus::Suspended])->save();
        $this->getJson('/api/v1/deals/'.$dealId)->assertStatus(403);

        $ambassador->forceFill(['status' => AccountStatus::Banned])->save();
        $this->getJson('/api/v1/deals')->assertStatus(403);
    }

    public function test_failed_event_insert_rolls_back_the_deal(): void
    {
        $campaign = $this->eligibleCampaign();
        Sanctum::actingAs(User::factory()->ambassador()->create());

        DealEvent::creating(function (): void {
            throw new RuntimeException('event write failed');
        });

        $this->postJson('/api/v1/deals', ['campaign_id' => $campaign->id])
            ->assertStatus(500);

        $this->assertDatabaseCount('deals', 0);
        $this->assertDatabaseCount('deal_events', 0);
    }

    public function test_unknown_campaign_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->ambassador()->create());

        $this->postJson('/api/v1/deals', ['campaign_id' => 999999])
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);
    }

    /**
     * @param  array<string, mixed>  $campaignAttributes
     * @param  array<string, mixed>  $versionAttributes
     */
    private function eligibleCampaign(array $campaignAttributes = [], array $versionAttributes = []): Campaign
    {
        $owner = User::factory()->business()->create();
        $campaign = Campaign::factory()->for($owner)->create(array_merge([
            'title' => 'Eligible campaign',
            'status' => CampaignStatus::Active,
            'listing_starts_at' => now(),
            'listing_expires_at' => now()->addDays(30),
        ], $campaignAttributes));

        $version = CampaignVersion::factory()->for($campaign)->published()->create($versionAttributes);
        $campaign->forceFill(['current_campaign_version_id' => $version->id])->save();

        return $campaign->fresh(['currentVersion', 'user']);
    }
}
