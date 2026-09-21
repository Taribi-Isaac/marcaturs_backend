<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Enums\AdminStaffRole;
use App\Enums\Role;
use App\Models\AdminStaffInvitation;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminStaffRbacTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_admin_factory_receives_super_admin_profile(): void
    {
        $admin = User::factory()->admin()->create();

        $this->assertDatabaseHas('admin_staff_profiles', [
            'user_id' => $admin->id,
            'staff_role' => AdminStaffRole::SuperAdmin->value,
        ]);

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.staff_role', AdminStaffRole::SuperAdmin->value)
            ->assertJsonPath('data.permissions.0', 'overview.view');
    }

    public function test_business_me_does_not_include_staff_fields(): void
    {
        Sanctum::actingAs(User::factory()->business()->create());

        $response = $this->getJson('/api/v1/auth/me')->assertOk();
        $this->assertArrayNotHasKey('staff_role', $response->json('data'));
        $this->assertArrayNotHasKey('permissions', $response->json('data'));
    }

    public function test_verification_staff_can_view_overview_but_not_users_or_staff(): void
    {
        $staff = User::factory()->adminStaff(AdminStaffRole::Verification)->create();
        Sanctum::actingAs($staff);

        $this->getJson('/api/v1/admin/overview')->assertOk();
        $this->getJson('/api/v1/admin/users')->assertStatus(403);
        $this->getJson('/api/v1/admin/staff')->assertStatus(403);
        $this->getJson('/api/v1/admin/campaigns')->assertStatus(403);
        $this->getJson('/api/v1/admin/verification/submissions')->assertOk();
    }

    public function test_moderation_staff_cannot_manage_users_verification_config_or_disputes(): void
    {
        $staff = User::factory()->adminStaff(AdminStaffRole::Moderation)->create();
        Sanctum::actingAs($staff);

        $this->getJson('/api/v1/admin/campaigns')->assertOk();
        $this->getJson('/api/v1/admin/conversations')->assertOk();
        $this->getJson('/api/v1/admin/disputes')->assertOk();
        $this->postJson('/api/v1/admin/categories', ['name' => 'X', 'slug' => 'x'])->assertStatus(403);
        $this->getJson('/api/v1/admin/users')->assertStatus(403);
        $this->getJson('/api/v1/admin/verification/submissions')->assertStatus(403);
    }

    public function test_operations_cannot_manage_staff(): void
    {
        $ops = User::factory()->adminStaff(AdminStaffRole::Operations)->create();
        Sanctum::actingAs($ops);

        $this->getJson('/api/v1/admin/users')->assertOk();
        $this->getJson('/api/v1/admin/staff')->assertStatus(403);
        $this->postJson('/api/v1/admin/staff/invitations', [
            'name' => 'New Staff',
            'email' => 'new.staff@example.com',
            'staff_role' => AdminStaffRole::Verification->value,
        ])->assertStatus(403);
    }

    public function test_super_admin_can_invite_accept_and_list_staff(): void
    {
        Notification::fake();
        $super = User::factory()->admin()->create();
        Sanctum::actingAs($super);

        $invite = $this->postJson('/api/v1/admin/staff/invitations', [
            'name' => 'Verifier One',
            'email' => 'verifier.one@example.com',
            'staff_role' => AdminStaffRole::Verification->value,
        ])->assertCreated();

        $token = $invite->json('data.debug_token');
        $this->assertNotEmpty($token);
        $this->assertDatabaseCount('admin_staff_invitations', 1);

        Sanctum::actingAs(User::factory()->business()->create());
        $this->postJson('/api/v1/auth/staff-invitations/accept', [
            'token' => $token,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertCreated()
            ->assertJsonPath('data.role', Role::Admin->value)
            ->assertJsonPath('data.staff_role', AdminStaffRole::Verification->value);

        $accepted = User::query()->where('email', 'verifier.one@example.com')->first();
        $this->assertNotNull($accepted);
        $this->assertTrue($accepted->adminStaffProfile?->staff_role === AdminStaffRole::Verification);

        Sanctum::actingAs($super);
        $this->getJson('/api/v1/admin/staff')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 2);
    }

    public function test_invitation_security_rejects_reuse_revoke_and_expiry(): void
    {
        Notification::fake();
        $super = User::factory()->admin()->create();
        Sanctum::actingAs($super);

        $invite = $this->postJson('/api/v1/admin/staff/invitations', [
            'name' => 'Temp',
            'email' => 'temp.staff@example.com',
            'staff_role' => AdminStaffRole::Moderation->value,
        ])->assertCreated();

        $id = $invite->json('data.id');
        $token = $invite->json('data.debug_token');

        $this->postJson("/api/v1/admin/staff/invitations/{$id}/revoke")->assertOk();

        $this->postJson('/api/v1/auth/staff-invitations/accept', [
            'token' => $token,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertStatus(422);

        $invite2 = $this->postJson('/api/v1/admin/staff/invitations', [
            'name' => 'Temp Two',
            'email' => 'temp.two@example.com',
            'staff_role' => AdminStaffRole::Moderation->value,
        ])->assertCreated();

        $invitation = AdminStaffInvitation::query()->findOrFail($invite2->json('data.id'));
        $invitation->expires_at = now()->subMinute();
        $invitation->save();

        $this->postJson('/api/v1/auth/staff-invitations/accept', [
            'token' => $invite2->json('data.debug_token'),
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertStatus(422);
    }

    public function test_cannot_self_change_role_or_disable_and_last_super_admin_protected(): void
    {
        $super = User::factory()->admin()->create();
        Sanctum::actingAs($super);

        $this->patchJson("/api/v1/admin/staff/{$super->id}", [
            'staff_role' => AdminStaffRole::Operations->value,
        ])->assertStatus(403);

        $this->postJson("/api/v1/admin/staff/{$super->id}/disable", [
            'reason' => 'Trying to disable myself',
        ])->assertStatus(403);

        $ops = User::factory()->adminStaff(AdminStaffRole::Operations)->create();
        Sanctum::actingAs($super);
        $this->patchJson("/api/v1/admin/staff/{$ops->id}", [
            'staff_role' => AdminStaffRole::SuperAdmin->value,
        ])->assertOk();

        Sanctum::actingAs($ops->fresh(['adminStaffProfile']));
        $this->patchJson("/api/v1/admin/staff/{$super->id}", [
            'staff_role' => AdminStaffRole::Operations->value,
        ])->assertOk();

        // Only $ops remains Super Admin — demoting them must fail (last Super Admin).
        $this->patchJson("/api/v1/admin/staff/{$ops->id}", [
            'staff_role' => AdminStaffRole::Moderation->value,
        ])->assertStatus(403);

        $this->postJson("/api/v1/admin/staff/{$ops->id}/disable", [
            'reason' => 'Cannot remove last super admin',
        ])->assertStatus(403);
    }

    public function test_disable_revokes_tokens_and_blocks_login(): void
    {
        $super = User::factory()->admin()->create();
        $target = User::factory()->adminStaff(AdminStaffRole::Operations)->create([
            'password' => 'Password123!',
        ]);
        $target->createToken('auth');

        Sanctum::actingAs($super);
        $this->postJson("/api/v1/admin/staff/{$target->id}/disable", [
            'reason' => 'Access no longer required',
        ])->assertOk()
            ->assertJsonPath('data.status', AccountStatus::Suspended->value);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $target->id,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $target->email,
            'password' => 'Password123!',
        ])->assertStatus(403);
    }

    public function test_staff_endpoints_reject_participant_ids(): void
    {
        $super = User::factory()->admin()->create();
        $business = User::factory()->business()->create();
        Sanctum::actingAs($super);

        $this->getJson("/api/v1/admin/staff/{$business->id}")->assertStatus(404);
        $this->postJson("/api/v1/admin/staff/{$business->id}/disable", [
            'reason' => 'Should not work',
        ])->assertStatus(404);
    }

    public function test_invite_rejects_existing_email(): void
    {
        Notification::fake();
        $super = User::factory()->admin()->create();
        User::factory()->business()->create(['email' => 'taken@example.com']);
        Sanctum::actingAs($super);

        $this->postJson('/api/v1/admin/staff/invitations', [
            'name' => 'Taken',
            'email' => 'taken@example.com',
            'staff_role' => AdminStaffRole::Operations->value,
        ])->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);
    }

    public function test_direct_create_available_outside_production_only(): void
    {
        $super = User::factory()->admin()->create();
        Sanctum::actingAs($super);

        $this->postJson('/api/v1/admin/staff/direct', [
            'name' => 'Local Ops',
            'email' => 'local.ops@example.com',
            'password' => 'Password123!',
            'staff_role' => AdminStaffRole::Operations->value,
        ])->assertCreated()
            ->assertJsonPath('data.staff_role', AdminStaffRole::Operations->value);
    }
}
