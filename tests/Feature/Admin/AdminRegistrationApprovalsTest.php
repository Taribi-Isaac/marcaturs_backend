<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminStaffRole;
use App\Enums\RegistrationApprovalEventAction;
use App\Enums\RegistrationApprovalStatus;
use App\Enums\Role;
use App\Models\RegistrationApproval;
use App\Models\RegistrationApprovalEvent;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminRegistrationApprovalsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/registration-approvals';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_super_admin_can_create_list_show_and_revoke_approvals(): void
    {
        $super = User::factory()->admin()->create(['name' => 'Super One']);
        Sanctum::actingAs($super);

        $created = $this->postJson(self::BASE, [
            'email' => '  Pilot.Business@Example.com ',
            'role' => 'business',
            'note' => 'Pilot cohort A',
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', 'pilot.business@example.com')
            ->assertJsonPath('data.role', Role::Business->value)
            ->assertJsonPath('data.status', RegistrationApprovalStatus::Pending->value)
            ->assertJsonPath('data.note', 'Pilot cohort A')
            ->assertJsonPath('data.created_by.id', $super->id)
            ->assertJsonPath('data.revoked_at', null)
            ->assertJsonPath('data.consumed_at', null)
            ->assertJsonMissingPath('data.active_email');

        $id = $created->json('data.id');

        $this->postJson(self::BASE, ['email' => 'pilot.ambassador@example.com', 'role' => 'AMBASSADOR'])
            ->assertCreated();

        $this->getJson(self::BASE)
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('data.0.email', 'pilot.ambassador@example.com');

        $this->getJson(self::BASE.'?role=BUSINESS')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $id);

        $this->getJson(self::BASE.'?q=PILOT.AMB')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1);

        $this->postJson(self::BASE."/{$id}/revoke", ['reason' => 'Business withdrew from pilot'])
            ->assertOk()
            ->assertJsonPath('data.status', RegistrationApprovalStatus::Revoked->value)
            ->assertJsonPath('data.revoked_by.id', $super->id);

        $this->getJson(self::BASE.'?status=revoked')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $id);

        $this->getJson(self::BASE."/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', RegistrationApprovalStatus::Revoked->value)
            ->assertJsonPath('data.events.0.action', RegistrationApprovalEventAction::Revoked->value)
            ->assertJsonPath('data.events.0.reason', 'Business withdrew from pilot')
            ->assertJsonPath('data.events.0.actor.id', $super->id)
            ->assertJsonPath('data.events.1.action', RegistrationApprovalEventAction::Created->value)
            ->assertJsonPath('data.events.1.previous_status', null)
            ->assertJsonPath('data.events.1.new_status', RegistrationApprovalStatus::Pending->value);

        $this->assertNull(RegistrationApproval::query()->findOrFail($id)->active_email);
        Notification::assertNothingSent();
    }

    public function test_create_and_revoke_are_audited_without_duplicating_the_email(): void
    {
        $super = User::factory()->admin()->create();
        Sanctum::actingAs($super);

        $id = $this->postJson(self::BASE, ['email' => 'pilot.business@example.com', 'role' => 'BUSINESS'])
            ->assertCreated()
            ->json('data.id');

        $this->postJson(self::BASE."/{$id}/revoke")->assertOk();

        $events = RegistrationApprovalEvent::query()->where('registration_approval_id', $id)->orderBy('id')->get();
        $this->assertCount(2, $events);

        $this->assertSame(RegistrationApprovalEventAction::Created, $events[0]->action);
        $this->assertSame($super->id, $events[0]->actor_user_id);
        $this->assertNull($events[0]->previous_status);
        $this->assertSame(RegistrationApprovalStatus::Pending, $events[0]->new_status);
        $this->assertSame(['role' => Role::Business->value], $events[0]->meta);
        $this->assertNotNull($events[0]->created_at);

        $this->assertSame(RegistrationApprovalEventAction::Revoked, $events[1]->action);
        $this->assertSame($super->id, $events[1]->actor_user_id);
        $this->assertSame(RegistrationApprovalStatus::Pending, $events[1]->previous_status);
        $this->assertSame(RegistrationApprovalStatus::Revoked, $events[1]->new_status);
        $this->assertNull($events[1]->reason);

        foreach ($events as $event) {
            $this->assertStringNotContainsString('pilot.business@example.com', (string) json_encode($event->meta));
        }
    }

    public function test_non_super_admin_staff_cannot_manage_approvals(): void
    {
        $approval = $this->seedApproval();

        foreach ([AdminStaffRole::Operations, AdminStaffRole::Verification, AdminStaffRole::Moderation] as $role) {
            Sanctum::actingAs(User::factory()->adminStaff($role)->create());

            $this->assertAllEndpointsReturn(403, $approval->id);
        }

        $this->assertNothingChanged($approval);
    }

    public function test_participants_cannot_manage_approvals(): void
    {
        $approval = $this->seedApproval();

        foreach ([User::factory()->business()->create(), User::factory()->ambassador()->create()] as $participant) {
            Sanctum::actingAs($participant);

            $this->assertAllEndpointsReturn(403, $approval->id);
        }

        $this->assertNothingChanged($approval);
    }

    public function test_guests_cannot_manage_approvals(): void
    {
        $approval = $this->seedApproval();

        $this->assertAllEndpointsReturn(401, $approval->id);
        $this->assertNothingChanged($approval);
    }

    public function test_restricted_suspended_and_banned_super_admins_are_blocked_by_account_access(): void
    {
        $approval = $this->seedApproval();

        foreach (['restricted', 'suspended', 'banned'] as $state) {
            Sanctum::actingAs(User::factory()->admin()->{$state}()->create());

            $this->assertAllEndpointsReturn(403, $approval->id);
        }

        $this->assertNothingChanged($approval);
    }

    public function test_cookie_session_from_participant_origin_is_rejected(): void
    {
        config(['security.admin_origins' => ['https://admin.marcaturshub.com.ng']]);
        $this->actingAs(User::factory()->admin()->create(), 'web');

        $this->withHeader('Origin', 'https://app.marcaturshub.com.ng')
            ->postJson(self::BASE, ['email' => 'pilot.business@example.com', 'role' => 'BUSINESS'])
            ->assertStatus(403);

        $this->withHeader('Origin', 'https://admin.marcaturshub.com.ng')
            ->postJson(self::BASE, ['email' => 'pilot.business@example.com', 'role' => 'BUSINESS'])
            ->assertCreated();

        $this->assertDatabaseCount('registration_approvals', 1);
    }

    public function test_duplicate_active_approval_is_rejected_and_reapproval_after_revoke_is_allowed(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $id = $this->postJson(self::BASE, ['email' => 'pilot.business@example.com', 'role' => 'BUSINESS'])
            ->assertCreated()
            ->json('data.id');

        foreach (['BUSINESS', 'AMBASSADOR'] as $role) {
            $this->postJson(self::BASE, ['email' => 'PILOT.business@example.com', 'role' => $role])
                ->assertStatus(400)
                ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR)
                ->assertJsonStructure(['error' => ['details' => ['email']]]);
        }

        $this->postJson(self::BASE."/{$id}/revoke")->assertOk();

        $this->postJson(self::BASE, ['email' => 'pilot.business@example.com', 'role' => 'AMBASSADOR'])
            ->assertCreated()
            ->assertJsonPath('data.role', Role::Ambassador->value);

        $this->assertDatabaseCount('registration_approvals', 2);
    }

    public function test_approval_for_an_existing_account_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        User::factory()->business()->create(['email' => 'existing@example.com']);

        $this->postJson(self::BASE, ['email' => 'existing@example.com', 'role' => 'BUSINESS'])
            ->assertStatus(400)
            ->assertJsonStructure(['error' => ['details' => ['email']]]);

        $this->assertDatabaseCount('registration_approvals', 0);
    }

    public function test_invalid_email_and_role_values_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $invalid = [
            ['email' => 'not-an-email', 'role' => 'BUSINESS'],
            ['email' => '', 'role' => 'BUSINESS'],
            ['email' => str_repeat('a', 250).'@example.com', 'role' => 'BUSINESS'],
            ['email' => 'pilot@example.com'],
            ['email' => 'pilot@example.com', 'role' => 'ADMIN'],
            ['email' => 'pilot@example.com', 'role' => 'SUPER_ADMIN'],
            ['email' => 'pilot@example.com', 'role' => 'OPERATIONS'],
            ['email' => 'pilot@example.com', 'role' => 'CUSTOMER'],
            ['email' => 'pilot@example.com', 'role' => 'BUSINESS', 'note' => str_repeat('n', 501)],
        ];

        foreach ($invalid as $payload) {
            $this->postJson(self::BASE, $payload)
                ->assertStatus(400)
                ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);
        }

        $this->getJson(self::BASE.'?status=active')->assertStatus(400);
        $this->getJson(self::BASE.'?role=ADMIN')->assertStatus(400);

        $this->assertDatabaseCount('registration_approvals', 0);
    }

    public function test_consumed_and_revoked_approvals_cannot_be_revoked_again(): void
    {
        config(['registration.mode' => 'allowlist']);
        $super = User::factory()->admin()->create();
        Sanctum::actingAs($super);

        $consumedId = $this->postJson(self::BASE, ['email' => 'pilot.business@example.com', 'role' => 'BUSINESS'])->json('data.id');
        $revokedId = $this->postJson(self::BASE, ['email' => 'pilot.ambassador@example.com', 'role' => 'AMBASSADOR'])->json('data.id');

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Pilot Business',
            'email' => 'pilot.business@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'BUSINESS',
        ])->assertCreated();

        Sanctum::actingAs($super);
        $this->postJson(self::BASE."/{$revokedId}/revoke")->assertOk();

        $this->postJson(self::BASE."/{$consumedId}/revoke")
            ->assertStatus(422)
            ->assertJsonPath('error.code', ApiErrorCode::BUSINESS_VALIDATION);

        $this->postJson(self::BASE."/{$revokedId}/revoke")
            ->assertStatus(422)
            ->assertJsonPath('error.code', ApiErrorCode::BUSINESS_VALIDATION);

        $this->postJson(self::BASE.'/999999/revoke')->assertStatus(404);
        $this->getJson(self::BASE.'/999999')->assertStatus(404);

        $this->assertSame(RegistrationApprovalStatus::Consumed, RegistrationApproval::query()->findOrFail($consumedId)->status);
        $this->assertSame(1, RegistrationApprovalEvent::query()->where('registration_approval_id', $revokedId)
            ->where('action', RegistrationApprovalEventAction::Revoked)->count());

        $this->getJson(self::BASE."/{$consumedId}")
            ->assertOk()
            ->assertJsonPath('data.consumed_by_user_id', User::query()->where('email', 'pilot.business@example.com')->value('id'))
            ->assertJsonPath('data.events.0.action', RegistrationApprovalEventAction::Consumed->value);
    }

    public function test_database_rejects_two_non_revoked_approvals_for_the_same_email(): void
    {
        $super = User::factory()->admin()->create();
        $this->seedApproval($super);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('registration_approvals')->insert([
            'email' => 'pilot.business@example.com',
            'active_email' => 'pilot.business@example.com',
            'role' => Role::Ambassador->value,
            'status' => RegistrationApprovalStatus::Pending->value,
            'created_by_user_id' => $super->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_concurrent_create_that_hits_the_unique_index_returns_a_validation_error(): void
    {
        $super = User::factory()->admin()->create();
        Sanctum::actingAs($super);

        // Simulate a competing request inserting the same email after this request's checks.
        $raced = false;
        DB::connection()->beforeExecuting(function (string $query) use (&$raced, $super): void {
            if ($raced || preg_match('/^insert into [`"]?registration_approvals[`"]? /i', $query) !== 1) {
                return;
            }

            $raced = true;
            DB::table('registration_approvals')->insert([
                'email' => 'pilot.business@example.com',
                'active_email' => 'pilot.business@example.com',
                'role' => Role::Business->value,
                'status' => RegistrationApprovalStatus::Pending->value,
                'created_by_user_id' => $super->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->postJson(self::BASE, ['email' => 'pilot.business@example.com', 'role' => 'BUSINESS'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR)
            ->assertJsonStructure(['error' => ['details' => ['email']]]);

        $this->assertTrue($raced);
        $this->assertDatabaseCount('registration_approval_events', 0);
    }

    private function seedApproval(?User $creator = null): RegistrationApproval
    {
        $approval = new RegistrationApproval;
        $approval->email = 'pilot.business@example.com';
        $approval->active_email = 'pilot.business@example.com';
        $approval->role = Role::Business;
        $approval->status = RegistrationApprovalStatus::Pending;
        $approval->created_by_user_id = ($creator ?? User::factory()->admin()->create())->id;
        $approval->save();

        return $approval;
    }

    private function assertAllEndpointsReturn(int $status, int $approvalId): void
    {
        $this->getJson(self::BASE)->assertStatus($status);
        $this->getJson(self::BASE."/{$approvalId}")->assertStatus($status);
        $this->postJson(self::BASE, ['email' => 'new.pilot@example.com', 'role' => 'BUSINESS'])->assertStatus($status);
        $this->postJson(self::BASE."/{$approvalId}/revoke")->assertStatus($status);
    }

    private function assertNothingChanged(RegistrationApproval $approval): void
    {
        $this->assertDatabaseCount('registration_approvals', 1);
        $this->assertDatabaseCount('registration_approval_events', 0);
        $this->assertSame(RegistrationApprovalStatus::Pending, $approval->fresh()?->status);
    }
}
