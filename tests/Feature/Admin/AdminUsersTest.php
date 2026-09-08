<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Enums\CampaignStatus;
use App\Enums\CommissionStatus;
use App\Enums\DealStatus;
use App\Enums\DisputeStatus;
use App\Enums\NotificationType;
use App\Enums\Role;
use App\Enums\UserStatusAction;
use App\Models\AmbassadorProfile;
use App\Models\BusinessProfile;
use App\Models\Campaign;
use App\Models\CampaignVersion;
use App\Models\Commission;
use App\Models\Deal;
use App\Models\Dispute;
use App\Models\DisputeCategory;
use App\Models\User;
use App\Models\UserStatusEvent;
use App\Notifications\AccountStatusChangedNotification;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_and_non_admin_cannot_list_users(): void
    {
        $this->getJson('/api/v1/admin/users')
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);

        Sanctum::actingAs(User::factory()->business()->create());
        $this->getJson('/api/v1/admin/users')->assertStatus(403);

        Sanctum::actingAs(User::factory()->ambassador()->create());
        $this->getJson('/api/v1/admin/users')->assertStatus(403);
    }

    public function test_admin_can_list_business_and_ambassador_users_with_filters_and_search(): void
    {
        $admin = User::factory()->admin()->create();
        $business = User::factory()->business()->create([
            'name' => 'Ada Solar Owner',
            'email' => 'ada.solar@example.com',
            'status' => AccountStatus::Active,
        ]);
        BusinessProfile::factory()->for($business)->create([
            'legal_name' => 'Ada Solar Ventures Ltd',
            'trading_name' => 'Ada Solar',
        ]);
        $ambassador = User::factory()->ambassador()->create([
            'name' => 'Chidi Marketer',
            'email' => 'chidi@example.com',
            'status' => AccountStatus::Restricted,
        ]);
        AmbassadorProfile::factory()->for($ambassador)->create([
            'display_name' => 'Chidi Growth',
        ]);
        User::factory()->admin()->create(['email' => 'hidden.admin@example.com']);
        User::factory()->business()->suspended()->create(['email' => 'suspended.biz@example.com']);

        Sanctum::actingAs($admin);

        $all = $this->getJson('/api/v1/admin/users')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 3);
        $roles = collect($all->json('data'))->pluck('role')->all();
        $this->assertContains(Role::Business->value, $roles);
        $this->assertContains(Role::Ambassador->value, $roles);
        $this->assertNotContains(Role::Admin->value, $roles);
        $this->assertArrayNotHasKey('password', $all->json('data.0'));
        $this->assertArrayNotHasKey('remember_token', $all->json('data.0'));

        $this->getJson('/api/v1/admin/users?role=BUSINESS')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 2);

        $this->getJson('/api/v1/admin/users?role=AMBASSADOR')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $ambassador->id);

        $this->getJson('/api/v1/admin/users?status=restricted')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $ambassador->id);

        $this->getJson('/api/v1/admin/users?q=Ada%20Solar')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $business->id);

        $this->getJson('/api/v1/admin/users?q=Chidi%20Growth')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $ambassador->id);

        $this->getJson('/api/v1/admin/users?role=ADMIN')
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);
    }

    public function test_list_pagination_and_per_page_maximum(): void
    {
        User::factory()->business()->count(20)->create();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/users?per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.pagination.per_page', 10)
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.pagination.total', 20);

        $this->getJson('/api/v1/admin/users?per_page=101')
            ->assertStatus(400);

        $page2 = $this->getJson('/api/v1/admin/users?per_page=15&page=2')
            ->assertOk()
            ->assertJsonPath('meta.pagination.current_page', 2);
        $this->assertCount(5, $page2->json('data'));
    }

    public function test_admin_can_show_business_and_ambassador_detail(): void
    {
        $business = User::factory()->business()->create();
        BusinessProfile::factory()->for($business)->create(['legal_name' => 'Detail Biz Ltd']);
        $campaign = Campaign::factory()->for($business)->create(['status' => CampaignStatus::Active]);
        CampaignVersion::factory()->for($campaign)->published()->create();
        $campaign->forceFill(['current_campaign_version_id' => $campaign->versions()->first()->id])->save();

        $ambassador = User::factory()->ambassador()->create();
        AmbassadorProfile::factory()->for($ambassador)->create(['display_name' => 'Detail Ambassador']);

        $deal = Deal::factory()->create([
            'business_user_id' => $business->id,
            'ambassador_user_id' => $ambassador->id,
            'campaign_id' => $campaign->id,
            'campaign_version_id' => $campaign->current_campaign_version_id,
            'status' => DealStatus::Sealed,
        ]);
        $commission = new Commission;
        $commission->forceFill([
            'deal_id' => $deal->id,
            'business_user_id' => $business->id,
            'ambassador_user_id' => $ambassador->id,
            'campaign_version_id' => $campaign->current_campaign_version_id,
            'commission_type' => 'percentage',
            'commission_rate' => '10.00',
            'amount' => '1000.00',
            'currency' => 'NGN',
            'status' => CommissionStatus::Due,
            'became_due_at' => now()->subDays(10),
            'due_at' => now()->subDay(),
        ])->save();
        $categoryId = DisputeCategory::query()->where('code', 'other')->value('id');
        $dispute = new Dispute;
        $dispute->forceFill([
            'reference' => 'MH-D-ADMIN-USER-1',
            'deal_id' => $deal->id,
            'commission_id' => $commission->id,
            'category_id' => $categoryId,
            'reporter_user_id' => $ambassador->id,
            'accused_user_id' => $business->id,
            'description' => 'Open dispute for counts.',
            'status' => DisputeStatus::Submitted,
        ])->save();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/users/'.$business->id)
            ->assertOk()
            ->assertJsonPath('data.id', $business->id)
            ->assertJsonPath('data.role', Role::Business->value)
            ->assertJsonPath('data.profile.legal_name', 'Detail Biz Ltd')
            ->assertJsonPath('data.counts.campaigns', 1)
            ->assertJsonPath('data.counts.deals', 1)
            ->assertJsonPath('data.counts.open_disputes', 1)
            ->assertJsonPath('data.counts.commissions_due', 1)
            ->assertJsonPath('data.counts.commissions_overdue', 1)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.payment_account_identifier');

        $this->getJson('/api/v1/admin/users/'.$ambassador->id)
            ->assertOk()
            ->assertJsonPath('data.profile.display_name', 'Detail Ambassador')
            ->assertJsonPath('data.counts.deals', 1);
    }

    public function test_admin_target_and_missing_user_are_not_exposed(): void
    {
        $adminTarget = User::factory()->admin()->create();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/users/'.$adminTarget->id)
            ->assertStatus(404);

        $this->getJson('/api/v1/admin/users/999999')
            ->assertStatus(404);
    }

    public function test_status_mutations_follow_transition_matrix_and_require_reason(): void
    {
        Notification::fake();

        $actor = User::factory()->admin()->create();
        $target = User::factory()->business()->create(['status' => AccountStatus::Active]);
        Sanctum::actingAs($actor);

        $this->postJson('/api/v1/admin/users/'.$target->id.'/restrict', [])
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);

        $this->postJson('/api/v1/admin/users/'.$target->id.'/restrict', [
            'reason' => 'Incomplete verification follow-up.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', AccountStatus::Restricted->value);

        $this->assertDatabaseHas('user_status_events', [
            'actor_user_id' => $actor->id,
            'target_user_id' => $target->id,
            'action' => UserStatusAction::Restrict->value,
            'previous_status' => AccountStatus::Active->value,
            'new_status' => AccountStatus::Restricted->value,
        ]);

        Notification::assertSentTo($target, AccountStatusChangedNotification::class);

        $this->postJson('/api/v1/admin/users/'.$target->id.'/suspend', [
            'reason' => 'Escalating restriction to suspension.',
        ])->assertOk()->assertJsonPath('data.status', AccountStatus::Suspended->value);

        $this->postJson('/api/v1/admin/users/'.$target->id.'/restrict', [
            'reason' => 'Not allowed from suspended.',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ApiErrorCode::BUSINESS_VALIDATION);

        $this->postJson('/api/v1/admin/users/'.$target->id.'/restore', [
            'reason' => 'Cleared after review.',
        ])->assertOk()->assertJsonPath('data.status', AccountStatus::Active->value);

        $this->postJson('/api/v1/admin/users/'.$target->id.'/ban', [
            'reason' => 'Serious policy violation.',
        ])->assertOk()->assertJsonPath('data.status', AccountStatus::Banned->value);

        $this->postJson('/api/v1/admin/users/'.$target->id.'/suspend', [
            'reason' => 'Cannot suspend a banned account.',
        ])->assertStatus(422);

        $this->postJson('/api/v1/admin/users/'.$target->id.'/restore', [
            'reason' => 'Ban lifted to restricted monitoring.',
        ])->assertOk()->assertJsonPath('data.status', AccountStatus::Restricted->value);

        $this->assertSame(5, UserStatusEvent::query()->where('target_user_id', $target->id)->count());
        $this->assertNotNull(UserStatusEvent::query()->where('target_user_id', $target->id)->value('reason'));
    }

    public function test_self_and_admin_targets_are_rejected_for_mutations(): void
    {
        $actor = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();
        Sanctum::actingAs($actor);

        $this->postJson('/api/v1/admin/users/'.$actor->id.'/suspend', [
            'reason' => 'Self sanction attempt.',
        ])->assertStatus(404);

        $this->postJson('/api/v1/admin/users/'.$otherAdmin->id.'/ban', [
            'reason' => 'Admin target attempt.',
        ])->assertStatus(404);
    }

    public function test_suspend_and_ban_revoke_tokens_while_restrict_preserves_them(): void
    {
        Notification::fake();
        $actor = User::factory()->admin()->create();
        Sanctum::actingAs($actor);

        $restricted = User::factory()->business()->create(['status' => AccountStatus::Active]);
        $restrictedToken = $restricted->createToken('keep')->plainTextToken;
        $this->postJson('/api/v1/admin/users/'.$restricted->id.'/restrict', [
            'reason' => 'Soft limit account.',
        ])->assertOk();
        $this->assertSame(1, $restricted->fresh()->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($restrictedToken)->getJson('/api/v1/auth/me')->assertOk();

        Sanctum::actingAs($actor);
        $suspended = User::factory()->business()->create(['status' => AccountStatus::Active]);
        $suspended->createToken('drop-me');
        $this->assertSame(1, $suspended->fresh()->tokens()->count());
        $this->postJson('/api/v1/admin/users/'.$suspended->id.'/suspend', [
            'reason' => 'Suspend and revoke.',
        ])->assertOk();
        $this->assertSame(0, $suspended->fresh()->tokens()->count());

        $banned = User::factory()->ambassador()->create(['status' => AccountStatus::Active]);
        $banned->createToken('drop-me-too');
        $this->postJson('/api/v1/admin/users/'.$banned->id.'/ban', [
            'reason' => 'Ban and revoke.',
        ])->assertOk();
        $this->assertSame(0, $banned->fresh()->tokens()->count());

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $actor->id);
    }

    public function test_sanctioned_business_campaigns_leave_marketplace_while_deals_remain_unchanged(): void
    {
        Notification::fake();

        $owner = User::factory()->business()->create(['status' => AccountStatus::Active]);
        $campaign = Campaign::factory()->for($owner)->create([
            'title' => 'Public solar campaign',
            'status' => CampaignStatus::Active,
            'listing_starts_at' => now()->subDay(),
            'listing_expires_at' => now()->addDays(10),
        ]);
        $version = CampaignVersion::factory()->for($campaign)->published()->create();
        $campaign->forceFill(['current_campaign_version_id' => $version->id])->save();

        $ambassador = User::factory()->ambassador()->create();
        $deal = Deal::factory()->create([
            'business_user_id' => $owner->id,
            'ambassador_user_id' => $ambassador->id,
            'campaign_id' => $campaign->id,
            'campaign_version_id' => $version->id,
            'status' => DealStatus::Sealed,
        ]);
        $commission = new Commission;
        $commission->forceFill([
            'deal_id' => $deal->id,
            'business_user_id' => $owner->id,
            'ambassador_user_id' => $ambassador->id,
            'campaign_version_id' => $version->id,
            'commission_type' => 'percentage',
            'commission_rate' => '10.00',
            'amount' => '5000.00',
            'currency' => 'NGN',
            'status' => CommissionStatus::Due,
            'became_due_at' => now(),
            'due_at' => now()->addDays(7),
        ])->save();

        $this->getJson('/api/v1/marketplace/campaigns/'.$campaign->id)->assertOk();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson('/api/v1/admin/users/'.$owner->id.'/suspend', [
            'reason' => 'Business under investigation.',
        ])->assertOk();

        $this->getJson('/api/v1/marketplace/campaigns/'.$campaign->id)->assertStatus(404);
        $this->getJson('/api/v1/marketplace/campaigns')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 0);

        $this->assertSame(CampaignStatus::Active, $campaign->fresh()->status);
        $this->assertSame(DealStatus::Sealed, $deal->fresh()->status);
        $this->assertSame(CommissionStatus::Due, $commission->fresh()->status);
        $this->assertSame('5000.00', $commission->fresh()->amount);

        $otherOwner = User::factory()->business()->create(['status' => AccountStatus::Active]);
        $visible = Campaign::factory()->for($otherOwner)->create([
            'title' => 'Still visible campaign',
            'status' => CampaignStatus::Active,
            'listing_starts_at' => now()->subHour(),
            'listing_expires_at' => now()->addDays(5),
        ]);
        $v2 = CampaignVersion::factory()->for($visible)->published()->create();
        $visible->forceFill(['current_campaign_version_id' => $v2->id])->save();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson('/api/v1/admin/users/'.$ambassador->id.'/ban', [
            'reason' => 'Ambassador ban must not hide unrelated campaigns.',
        ])->assertOk();

        $this->getJson('/api/v1/marketplace/campaigns/'.$visible->id)
            ->assertOk()
            ->assertJsonPath('data.title', 'Still visible campaign');
    }

    public function test_notification_type_is_account_status_changed(): void
    {
        Notification::fake();
        $target = User::factory()->business()->create();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/users/'.$target->id.'/ban', [
            'reason' => 'Policy ban.',
        ])->assertOk();

        Notification::assertSentTo(
            $target,
            AccountStatusChangedNotification::class,
            fn (AccountStatusChangedNotification $notification) => $notification->notificationType() === NotificationType::AccountStatusChanged,
        );
    }
}
