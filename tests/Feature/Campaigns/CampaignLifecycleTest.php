<?php

namespace Tests\Feature\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\CampaignVersionStatus;
use App\Models\BusinessProfile;
use App\Models\Campaign;
use App\Models\CampaignVersion;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CampaignLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_submit_requires_a_published_version_and_does_not_activate(): void
    {
        [$owner, $campaign] = $this->businessCampaign();
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/submit')
            ->assertStatus(422)
            ->assertJsonPath('error.code', ApiErrorCode::BUSINESS_VALIDATION);

        $this->publishVersion($owner, $campaign);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/submit')
            ->assertOk()
            ->assertJsonPath('data.status', CampaignStatus::Submitted->value)
            ->assertJsonPath('data.current_version.status', CampaignVersionStatus::Published->value);

        $this->assertSame(CampaignStatus::Submitted, $campaign->fresh()->status);
        $this->assertNotEquals(CampaignStatus::Active, $campaign->fresh()->status);
    }

    public function test_business_cannot_approve_or_activate_own_campaign(): void
    {
        [$owner, $campaign] = $this->submittedCampaign();
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/admin/campaigns/'.$campaign->id.'/approve')
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);

        $this->patchJson('/api/v1/campaigns/'.$campaign->id, ['status' => 'active'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::CONFLICT);
        $this->assertSame(CampaignStatus::Submitted, $campaign->fresh()->status);
    }

    public function test_admin_approval_and_activation_require_published_version_and_set_listing_window(): void
    {
        Carbon::setTestNow('2026-09-02 12:00:00');
        Config::set('campaigns.free_listing_days', 30);

        [$owner, $campaign] = $this->submittedCampaign();
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/campaigns/'.$campaign->id.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', CampaignStatus::Approved->value);

        $this->assertNull($campaign->fresh()->listing_starts_at);

        $this->postJson('/api/v1/admin/campaigns/'.$campaign->id.'/activate')
            ->assertOk()
            ->assertJsonPath('data.status', CampaignStatus::Active->value)
            ->assertJsonPath('data.listing_starts_at', '2026-09-02T12:00:00+00:00');

        $this->assertSame(
            '2026-10-02 12:00:00',
            $campaign->fresh()->listing_expires_at?->format('Y-m-d H:i:s'),
        );
        $this->assertSame(CampaignVersionStatus::Published, $campaign->fresh()->currentVersion?->status);
    }

    public function test_invalid_transitions_are_rejected_and_leave_status_unchanged(): void
    {
        [$owner, $campaign] = $this->businessCampaign();
        $this->publishVersion($owner, $campaign);
        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/campaigns/'.$campaign->id.'/approve')
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::CONFLICT);
        $this->assertSame(CampaignStatus::Draft, $campaign->fresh()->status);

        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/submit')->assertOk();
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/deactivate')
            ->assertStatus(409);
        $this->assertSame(CampaignStatus::Submitted, $campaign->fresh()->status);
    }

    public function test_reject_and_request_modification_return_campaign_to_draft(): void
    {
        [$owner, $campaign] = $this->submittedCampaign();
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/campaigns/'.$campaign->id.'/reject', [])
            ->assertStatus(400);

        $this->postJson('/api/v1/admin/campaigns/'.$campaign->id.'/reject', [
            'reason' => 'Commission terms are unclear.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', CampaignStatus::Draft->value)
            ->assertJsonPath('data.review_reason', 'Commission terms are unclear.');

        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/submit')->assertOk();

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/campaigns/'.$campaign->id.'/request-modification', [
            'reason' => 'Please revise the service area.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', CampaignStatus::Draft->value);
    }

    public function test_business_can_deactivate_active_campaign_without_deletion(): void
    {
        [$owner, $campaign] = $this->activeCampaign();
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/deactivate')
            ->assertOk()
            ->assertJsonPath('data.status', CampaignStatus::Deactivated->value);

        $this->assertDatabaseHas('campaigns', [
            'id' => $campaign->id,
            'status' => CampaignStatus::Deactivated->value,
        ]);
        $this->assertDatabaseCount('campaign_versions', 1);
    }

    public function test_admin_can_suspend_and_close_without_deleting(): void
    {
        [$owner, $campaign] = $this->activeCampaign();
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/campaigns/'.$campaign->id.'/suspend', [
            'reason' => 'Policy review.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', CampaignStatus::Suspended->value);

        $this->postJson('/api/v1/admin/campaigns/'.$campaign->id.'/close', [
            'reason' => 'Closed after review.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', CampaignStatus::Closed->value);

        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id]);
    }

    public function test_lifecycle_processor_expires_due_campaigns_and_can_mark_expiring(): void
    {
        Carbon::setTestNow('2026-09-10 12:00:00');
        Config::set('campaigns.expiring_lead_days', 0);
        [, $due] = $this->activeCampaign();
        $due->forceFill([
            'listing_expires_at' => now()->subMinute(),
        ])->save();

        $this->artisan('campaigns:process-lifecycle')->assertSuccessful();
        $this->assertSame(CampaignStatus::Expired, $due->fresh()->status);
        $this->assertNotNull($due->fresh()->expired_at);
        $this->assertDatabaseHas('campaigns', ['id' => $due->id]);

        Config::set('campaigns.expiring_lead_days', 3);
        [, $soon] = $this->activeCampaign();
        $soon->forceFill([
            'status' => CampaignStatus::Active,
            'listing_expires_at' => now()->addDays(2),
        ])->save();

        $this->artisan('campaigns:process-lifecycle')->assertSuccessful();
        $this->assertSame(CampaignStatus::Expiring, $soon->fresh()->status);

        Carbon::setTestNow('2026-09-13 12:00:01');
        $this->artisan('campaigns:process-lifecycle')->assertSuccessful();
        $this->assertSame(CampaignStatus::Expired, $soon->fresh()->status);
    }

    public function test_authorization_idor_and_account_status_rules(): void
    {
        [$owner, $campaign] = $this->submittedCampaign();
        $intruder = User::factory()->business()->create();
        BusinessProfile::factory()->for($intruder)->create();
        Sanctum::actingAs($intruder);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/submit')
            ->assertStatus(403);

        Sanctum::actingAs(User::factory()->ambassador()->create());
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/submit')->assertStatus(403);
        $this->postJson('/api/v1/admin/campaigns/'.$campaign->id.'/approve')->assertStatus(403);

        Sanctum::actingAs(User::factory()->business()->restricted()->create());
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/submit')->assertStatus(403);

        Sanctum::actingAs(User::factory()->business()->suspended()->create());
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/submit')->assertStatus(403);

        Sanctum::actingAs(User::factory()->business()->banned()->create());
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/submit')->assertStatus(403);

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/v1/admin/campaigns?status=submitted')
            ->assertOk()
            ->assertJsonPath('data.0.id', $campaign->id)
            ->assertJsonMissingPath('data.0.user.password');
    }

    public function test_submitted_campaigns_cannot_mutate_versions(): void
    {
        [$owner, $campaign] = $this->submittedCampaign();
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions', [
            'product_name' => 'Should not create',
        ])->assertStatus(409)->assertJsonPath('error.code', ApiErrorCode::CONFLICT);
    }

    public function test_activation_fails_if_current_version_is_only_a_draft(): void
    {
        [$owner, $campaign] = $this->businessCampaign();
        CampaignVersion::factory()->for($campaign)->create([
            'status' => CampaignVersionStatus::Draft,
            'version_number' => 1,
        ]);
        $campaign->forceFill(['status' => CampaignStatus::Approved])->save();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson('/api/v1/admin/campaigns/'.$campaign->id.'/activate')
            ->assertStatus(422)
            ->assertJsonPath('error.code', ApiErrorCode::BUSINESS_VALIDATION);
        $this->assertSame(CampaignStatus::Approved, $campaign->fresh()->status);
    }

    /**
     * @return array{0: User, 1: Campaign}
     */
    private function businessCampaign(): array
    {
        $owner = User::factory()->business()->create();
        BusinessProfile::factory()->for($owner)->create();
        $campaign = Campaign::factory()->for($owner)->create();

        return [$owner, $campaign];
    }

    /**
     * @return array{0: User, 1: Campaign}
     */
    private function submittedCampaign(): array
    {
        [$owner, $campaign] = $this->businessCampaign();
        $this->publishVersion($owner, $campaign);
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/submit')->assertOk();

        return [$owner, $campaign->fresh()];
    }

    /**
     * @return array{0: User, 1: Campaign}
     */
    private function activeCampaign(): array
    {
        [$owner, $campaign] = $this->submittedCampaign();
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/campaigns/'.$campaign->id.'/approve')->assertOk();
        $this->postJson('/api/v1/admin/campaigns/'.$campaign->id.'/activate')->assertOk();

        return [$owner, $campaign->fresh()];
    }

    private function publishVersion(User $owner, Campaign $campaign): void
    {
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions', [
            'product_name' => 'Classroom internet package',
            'pricing_method' => 'fixed',
            'price_amount' => 50000,
            'commission_type' => 'percentage',
            'commission_rate' => 10,
            'commission_trigger' => 'payment_confirmation',
            'commission_payment_deadline_days' => 7,
            'refund_cancellation_rules' => 'Published refund rules.',
            'payment_destination_name' => 'Ada Ventures Ltd',
            'payment_provider' => 'Example Bank',
            'payment_account_identifier' => '0000000000',
            'payment_instructions' => 'Pay the business directly.',
        ])->assertCreated();

        $this->postJson('/api/v1/campaigns/'.$campaign->id.'/versions/1/publish')->assertOk();
    }
}
