<?php

namespace Tests\Feature\Campaigns;

use App\Enums\CampaignVersionStatus;
use App\Enums\CommissionType;
use App\Models\BusinessProfile;
use App\Models\Campaign;
use App\Models\CampaignVersion;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CampaignVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_version_access_is_rejected(): void
    {
        $campaign = Campaign::factory()->create();

        $this->getJson('/api/v1/campaigns/'.$campaign->id.'/versions')
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);
    }

    public function test_ambassador_cannot_mutate_campaign_versions(): void
    {
        $campaign = Campaign::factory()->create();
        Sanctum::actingAs(User::factory()->ambassador()->create());

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions', [
            'product_name' => 'Should fail',
        ])->assertStatus(403)->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);
    }

    public function test_admin_cannot_mutate_business_campaign_versions(): void
    {
        $campaign = Campaign::factory()->create();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions', [
            'product_name' => 'Should fail',
        ])->assertStatus(403);
    }

    public function test_business_can_create_numbered_draft_versions_and_cannot_access_others(): void
    {
        $owner = User::factory()->business()->create();
        BusinessProfile::factory()->for($owner)->create();
        $campaign = Campaign::factory()->for($owner)->create(['title' => 'Classroom kit']);
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions', [
            'product_name' => 'Classroom internet package',
            'pricing_method' => 'fixed',
            'price_amount' => 50000,
            'commission_type' => 'percentage',
            'commission_rate' => 10,
            'commission_trigger' => 'payment_confirmation',
            'commission_payment_deadline_days' => 7,
        ])
            ->assertCreated()
            ->assertJsonPath('data.version_number', 1)
            ->assertJsonPath('data.status', CampaignVersionStatus::Draft->value)
            ->assertJsonPath('data.price_amount', '50000.00')
            ->assertJsonPath('data.commission_rate', '10.00');

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions', [
            'product_name' => 'Second draft should wait',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::CONFLICT);

        $this->getJson('/api/v1/campaigns/'.$campaign->id.'/versions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.version_number', 1);

        $intruder = User::factory()->business()->create();
        BusinessProfile::factory()->for($intruder)->create();
        Sanctum::actingAs($intruder);

        $this->getJson('/api/v1/campaigns/'.$campaign->id.'/versions')
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);

        $this->getJson('/api/v1/campaigns/'.$campaign->id.'/versions/1')
            ->assertStatus(403);
    }

    public function test_published_version_is_immutable_and_does_not_change_when_a_new_version_is_created(): void
    {
        $owner = User::factory()->business()->create();
        BusinessProfile::factory()->for($owner)->create();
        $campaign = Campaign::factory()->for($owner)->create();
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions', $this->publishablePayload([
            'price_amount' => 50000,
            'commission_rate' => 10,
        ]))->assertCreated();

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions/1/publish')
            ->assertOk()
            ->assertJsonPath('data.status', CampaignVersionStatus::Published->value)
            ->assertJsonPath('data.price_amount', '50000.00');

        $this->patchJson('/api/v1/campaigns/'.$campaign->id.'/versions/1', [
            'price_amount' => 99999,
            'commission_rate' => 50,
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::CONFLICT);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions/1/publish')
            ->assertStatus(409);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions', $this->publishablePayload([
            'product_name' => 'Classroom internet package v2',
            'price_amount' => 60000,
            'commission_rate' => 12,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.version_number', 2)
            ->assertJsonPath('data.status', CampaignVersionStatus::Draft->value);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions/2/publish')->assertOk();

        $this->getJson('/api/v1/campaigns/'.$campaign->id.'/versions/1')
            ->assertOk()
            ->assertJsonPath('data.price_amount', '50000.00')
            ->assertJsonPath('data.commission_rate', '10.00')
            ->assertJsonPath('data.status', 'published');

        $this->getJson('/api/v1/campaigns/'.$campaign->id)
            ->assertOk()
            ->assertJsonPath('data.current_version.version_number', 2);

        $this->assertSame('50000.00', CampaignVersion::query()->where('version_number', 1)->value('price_amount'));
        $this->assertSame('60000.00', CampaignVersion::query()->where('version_number', 2)->value('price_amount'));
    }

    public function test_draft_versions_can_be_updated_and_incomplete_drafts_cannot_be_published(): void
    {
        $owner = User::factory()->business()->create();
        BusinessProfile::factory()->for($owner)->create();
        $campaign = Campaign::factory()->for($owner)->create(['title' => 'Fallback name']);
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions', [])
            ->assertCreated()
            ->assertJsonPath('data.product_name', 'Fallback name')
            ->assertJsonPath('data.version_number', 1);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions/1/publish')
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);

        $this->patchJson('/api/v1/campaigns/'.$campaign->id.'/versions/1', $this->publishablePayload())
            ->assertOk()
            ->assertJsonPath('data.commission_type', CommissionType::Percentage->value);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions/1/publish')
            ->assertOk()
            ->assertJsonPath('data.status', 'published');
    }

    public function test_version_route_is_scoped_to_the_campaign(): void
    {
        $owner = User::factory()->business()->create();
        BusinessProfile::factory()->for($owner)->create();
        $first = Campaign::factory()->for($owner)->create();
        $second = Campaign::factory()->for($owner)->create();
        CampaignVersion::factory()->for($first)->create(['version_number' => 1, 'product_name' => 'First']);
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/campaigns/'.$second->id.'/versions/1')
            ->assertStatus(404)
            ->assertJsonPath('error.code', ApiErrorCode::NOT_FOUND);
    }

    public function test_duplicate_version_numbers_are_rejected_by_the_database(): void
    {
        $campaign = Campaign::factory()->create();
        CampaignVersion::factory()->for($campaign)->create(['version_number' => 1]);

        $this->expectException(UniqueConstraintViolationException::class);

        CampaignVersion::factory()->for($campaign)->create(['version_number' => 1]);
    }

    public function test_invalid_commission_type_is_rejected(): void
    {
        $owner = User::factory()->business()->create();
        BusinessProfile::factory()->for($owner)->create();
        $campaign = Campaign::factory()->for($owner)->create();
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions', [
            'commission_type' => 'points',
        ])->assertStatus(400)->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);
    }

    public function test_commission_deadline_accepts_one_to_seven_days_on_drafts_and_publish(): void
    {
        [$owner, $campaign] = $this->ownedCampaign();
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions', $this->publishablePayload())
            ->assertCreated();

        foreach ([1, 2, 3, 4, 5, 6, 7] as $days) {
            $this->patchJson('/api/v1/campaigns/'.$campaign->id.'/versions/1', [
                'commission_payment_deadline_days' => $days,
            ])
                ->assertOk()
                ->assertJsonPath('data.commission_payment_deadline_days', $days);
        }

        $this->patchJson('/api/v1/campaigns/'.$campaign->id.'/versions/1', [
            'commission_payment_deadline_days' => 1,
        ])->assertOk();

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions/1/publish')
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.commission_payment_deadline_days', 1);
    }

    public function test_commission_deadline_outside_one_to_seven_days_is_rejected_on_drafts(): void
    {
        [$owner, $campaign] = $this->ownedCampaign();
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions', $this->publishablePayload())
            ->assertCreated();

        foreach ([0, -1, 8, 30, 365, 7.5, 'abc', '7 days'] as $invalid) {
            $this->patchJson('/api/v1/campaigns/'.$campaign->id.'/versions/1', [
                'commission_payment_deadline_days' => $invalid,
            ])
                ->assertStatus(400)
                ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR)
                ->assertJsonPath('error.message', 'The given data was invalid.')
                ->assertJsonStructure(['error' => ['details' => ['commission_payment_deadline_days']]]);
        }

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions', [
            'commission_payment_deadline_days' => 30,
        ])->assertStatus(400);

        $this->assertSame(7, CampaignVersion::query()->where('campaign_id', $campaign->id)->value('commission_payment_deadline_days'));
    }

    public function test_missing_commission_deadline_saves_as_draft_but_blocks_publish(): void
    {
        [$owner, $campaign] = $this->ownedCampaign();
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions', $this->publishablePayload([
            'commission_payment_deadline_days' => null,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.commission_payment_deadline_days', null);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions/1/publish')
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR)
            ->assertJsonStructure(['error' => ['details' => ['commission_payment_deadline_days']]]);

        $this->assertSame(
            CampaignVersionStatus::Draft,
            CampaignVersion::query()->where('campaign_id', $campaign->id)->firstOrFail()->status,
        );
    }

    public function test_legacy_draft_with_deadline_above_ceiling_cannot_publish_until_corrected(): void
    {
        [$owner, $campaign] = $this->ownedCampaign();
        CampaignVersion::factory()->for($campaign)->create([
            'version_number' => 1,
            'commission_payment_deadline_days' => 14,
        ]);
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions/1/publish')
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR)
            ->assertJsonStructure(['error' => ['details' => ['commission_payment_deadline_days']]]);

        $this->getJson('/api/v1/campaigns/'.$campaign->id.'/versions/1')
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.commission_payment_deadline_days', 14);

        $this->patchJson('/api/v1/campaigns/'.$campaign->id.'/versions/1', [
            'commission_payment_deadline_days' => 7,
        ])->assertOk();

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions/1/publish')
            ->assertOk()
            ->assertJsonPath('data.commission_payment_deadline_days', 7);
    }

    public function test_published_historical_version_with_longer_deadline_is_not_rewritten(): void
    {
        [$owner, $campaign] = $this->ownedCampaign();
        $historical = CampaignVersion::factory()->for($campaign)->published()->create([
            'version_number' => 1,
            'commission_payment_deadline_days' => 14,
        ]);
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/campaigns/'.$campaign->id.'/versions/1')
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.commission_payment_deadline_days', 14);

        $this->patchJson('/api/v1/campaigns/'.$campaign->id.'/versions/1', [
            'commission_payment_deadline_days' => 7,
        ])->assertStatus(409);

        $this->assertSame(14, $historical->fresh()->commission_payment_deadline_days);
    }

    public function test_deadline_validation_follows_the_deal_commission_ceiling(): void
    {
        config(['deals.commission_payment_ceiling_days' => 5]);
        [$owner, $campaign] = $this->ownedCampaign();
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions', $this->publishablePayload([
            'commission_payment_deadline_days' => 6,
        ]))->assertStatus(400);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions', $this->publishablePayload([
            'commission_payment_deadline_days' => 5,
        ]))->assertCreated();
    }

    public function test_deadline_updates_keep_campaign_version_ownership_rules(): void
    {
        [, $campaign] = $this->ownedCampaign();
        CampaignVersion::factory()->for($campaign)->create(['version_number' => 1]);

        $intruder = User::factory()->business()->create();
        BusinessProfile::factory()->for($intruder)->create();
        Sanctum::actingAs($intruder);
        $this->patchJson('/api/v1/campaigns/'.$campaign->id.'/versions/1', [
            'commission_payment_deadline_days' => 5,
        ])->assertStatus(403)->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);

        Sanctum::actingAs(User::factory()->ambassador()->create());
        $this->patchJson('/api/v1/campaigns/'.$campaign->id.'/versions/1', [
            'commission_payment_deadline_days' => 5,
        ])->assertStatus(403);

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions/1/publish')->assertStatus(403);

        $this->assertSame(7, CampaignVersion::query()->where('campaign_id', $campaign->id)->value('commission_payment_deadline_days'));
    }

    /**
     * @return array{0: User, 1: Campaign}
     */
    private function ownedCampaign(): array
    {
        $owner = User::factory()->business()->create();
        BusinessProfile::factory()->for($owner)->create();

        return [$owner, Campaign::factory()->for($owner)->create()];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function publishablePayload(array $overrides = []): array
    {
        return array_merge([
            'product_name' => 'Classroom internet package',
            'product_description' => 'Connectivity for classrooms.',
            'pricing_method' => 'fixed',
            'price_amount' => 50000,
            'price_currency' => 'NGN',
            'service_area' => 'Lagos',
            'commission_type' => 'percentage',
            'commission_rate' => 10,
            'commission_trigger' => 'payment_confirmation',
            'commission_payment_deadline_days' => 7,
            'refund_cancellation_rules' => 'Valid refunds within the settlement window follow these published rules.',
            'payment_destination_name' => 'Ada Ventures Ltd',
            'payment_provider' => 'Example Bank',
            'payment_account_identifier' => '0000000000',
            'payment_instructions' => 'Pay the business directly. MarcatursHub does not receive this payment.',
            'terms' => 'Campaign participation terms.',
        ], $overrides);
    }
}
