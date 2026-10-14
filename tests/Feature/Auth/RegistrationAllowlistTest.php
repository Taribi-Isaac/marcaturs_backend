<?php

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Enums\RegistrationApprovalEventAction;
use App\Enums\RegistrationApprovalStatus;
use App\Enums\RegistrationMode;
use App\Enums\Role;
use App\Models\RegistrationApproval;
use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Services\Auth\AuthenticationService;
use App\Services\Auth\RegistrationApprovalService;
use App\Support\Api\ApiErrorCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationAllowlistTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->superAdmin = User::factory()->admin()->create();
    }

    public function test_default_mode_is_open(): void
    {
        $this->assertSame('open', config('registration.mode'));
        $this->assertSame(RegistrationMode::Open, RegistrationMode::current());
    }

    public function test_unknown_mode_fails_closed_to_allowlist(): void
    {
        foreach (['', 'closed', 'OPEN-ish', null] as $value) {
            config(['registration.mode' => $value]);
            $this->assertSame(RegistrationMode::Allowlist, RegistrationMode::current());
        }

        config(['registration.mode' => ' Open ']);
        $this->assertSame(RegistrationMode::Open, RegistrationMode::current());
    }

    public function test_open_mode_preserves_existing_registration_without_any_approval(): void
    {
        config(['registration.mode' => 'open']);

        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.user.role', Role::Business->value)
            ->assertJsonPath('data.user.status', AccountStatus::Active->value)
            ->assertJsonPath('data.token_type', 'Bearer');

        $user = User::query()->where('email', 'pilot.business@example.com')->firstOrFail();
        Notification::assertSentTo($user, VerifyEmailNotification::class);
        $this->assertDatabaseCount('registration_approvals', 0);
        $this->assertDatabaseCount('registration_approval_events', 0);
    }

    public function test_open_mode_ignores_pending_approvals(): void
    {
        config(['registration.mode' => 'open']);
        $approval = $this->approve('pilot.business@example.com', Role::Business);

        $this->postJson('/api/v1/auth/register', $this->payload(['role' => 'AMBASSADOR']))
            ->assertCreated()
            ->assertJsonPath('data.user.role', Role::Ambassador->value);

        $this->assertSame(RegistrationApprovalStatus::Pending, $approval->fresh()?->status);
    }

    public function test_allowlist_rejects_unapproved_email_without_side_effects(): void
    {
        $this->enableAllowlist();

        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN)
            ->assertJsonPath('error.message', RegistrationApprovalService::NOT_APPROVED_MESSAGE)
            ->assertJsonMissingPath('data');

        $this->assertNoRegistrationSideEffects();
    }

    public function test_allowlist_accepts_approved_business_with_matching_role(): void
    {
        $this->enableAllowlist();
        $approval = $this->approve('pilot.business@example.com', Role::Business);

        $response = $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.user.role', Role::Business->value)
            ->assertJsonPath('data.user.status', AccountStatus::Active->value)
            ->assertJsonPath('data.user.email_verified_at', null)
            ->assertJsonPath('data.token_type', 'Bearer');

        $this->assertNotEmpty($response->json('data.token'));

        $user = User::query()->where('email', 'pilot.business@example.com')->firstOrFail();
        Notification::assertSentTo($user, VerifyEmailNotification::class);

        $approval->refresh();
        $this->assertSame(RegistrationApprovalStatus::Consumed, $approval->status);
        $this->assertSame($user->id, $approval->consumed_by_user_id);
        $this->assertNotNull($approval->consumed_at);
        $this->assertSame('pilot.business@example.com', $approval->active_email);

        $this->assertDatabaseHas('registration_approval_events', [
            'registration_approval_id' => $approval->id,
            'actor_user_id' => $user->id,
            'action' => RegistrationApprovalEventAction::Consumed->value,
            'previous_status' => RegistrationApprovalStatus::Pending->value,
            'new_status' => RegistrationApprovalStatus::Consumed->value,
        ]);
    }

    public function test_allowlist_accepts_approved_ambassador_with_matching_role(): void
    {
        $this->enableAllowlist();
        $this->approve('pilot.ambassador@example.com', Role::Ambassador);

        $this->postJson('/api/v1/auth/register', $this->payload([
            'email' => 'pilot.ambassador@example.com',
            'role' => 'AMBASSADOR',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.user.role', Role::Ambassador->value);
    }

    public function test_allowlist_rejects_approved_email_with_the_wrong_role(): void
    {
        $this->enableAllowlist();
        $business = $this->approve('pilot.business@example.com', Role::Business);
        $ambassador = $this->approve('pilot.ambassador@example.com', Role::Ambassador);

        $this->postJson('/api/v1/auth/register', $this->payload(['role' => 'AMBASSADOR']))
            ->assertStatus(403)
            ->assertJsonPath('error.message', RegistrationApprovalService::NOT_APPROVED_MESSAGE);

        $this->postJson('/api/v1/auth/register', $this->payload([
            'email' => 'pilot.ambassador@example.com',
            'role' => 'BUSINESS',
        ]))->assertStatus(403);

        $this->assertNoRegistrationSideEffects();
        $this->assertSame(RegistrationApprovalStatus::Pending, $business->fresh()?->status);
        $this->assertSame(RegistrationApprovalStatus::Pending, $ambassador->fresh()?->status);
    }

    public function test_unapproved_and_wrong_role_rejections_are_indistinguishable(): void
    {
        $this->enableAllowlist();
        $this->approve('pilot.business@example.com', Role::Business);

        $wrongRole = $this->postJson('/api/v1/auth/register', $this->payload(['role' => 'AMBASSADOR']));
        $unapproved = $this->postJson('/api/v1/auth/register', $this->payload(['email' => 'stranger@example.com']));

        $this->assertSame($unapproved->status(), $wrongRole->status());
        $this->assertSame($unapproved->json(), $wrongRole->json());
    }

    public function test_privileged_roles_cannot_register_even_with_an_approval_row(): void
    {
        $this->enableAllowlist();
        config(['api.rate_limits.registration_per_minute' => 100]);
        $this->approve('pilot.business@example.com', Role::Business);

        $admin = new RegistrationApproval;
        $admin->email = 'would.be.admin@example.com';
        $admin->active_email = 'would.be.admin@example.com';
        $admin->role = Role::Admin;
        $admin->status = RegistrationApprovalStatus::Pending;
        $admin->created_by_user_id = $this->superAdmin->id;
        $admin->save();

        foreach (['ADMIN', 'admin', 'SUPER_ADMIN', 'OPERATIONS'] as $role) {
            foreach (['pilot.business@example.com', 'would.be.admin@example.com'] as $email) {
                $this->postJson('/api/v1/auth/register', $this->payload(['email' => $email, 'role' => $role]))
                    ->assertStatus(400)
                    ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);
            }
        }

        $this->assertNoRegistrationSideEffects();
        $this->assertDatabaseMissing('users', ['role' => Role::Admin->value, 'email' => 'would.be.admin@example.com']);
    }

    public function test_email_is_matched_after_existing_normalization_only(): void
    {
        $this->enableAllowlist();
        $this->approve('pilot.business@example.com', Role::Business);
        $this->approve('first.last+pilot@example.com', Role::Ambassador);

        $this->postJson('/api/v1/auth/register', $this->payload(['email' => '  Pilot.Business@Example.COM ']))
            ->assertCreated();

        foreach (['first.last@example.com', 'firstlast+pilot@example.com'] as $email) {
            $this->postJson('/api/v1/auth/register', $this->payload(['email' => $email, 'role' => 'AMBASSADOR']))
                ->assertStatus(403);
        }

        $this->postJson('/api/v1/auth/register', $this->payload(['email' => 'first.last+pilot@example.com', 'role' => 'AMBASSADOR']))
            ->assertCreated();
    }

    public function test_revoked_approval_cannot_be_used(): void
    {
        $this->enableAllowlist();
        $approval = $this->approve('pilot.business@example.com', Role::Business);
        app(RegistrationApprovalService::class)->revoke($this->superAdmin, $approval->id, null);

        $this->postJson('/api/v1/auth/register', $this->payload())->assertStatus(403);

        $this->assertNoRegistrationSideEffects();
    }

    public function test_consumed_approval_cannot_be_used_again(): void
    {
        $this->enableAllowlist();
        $approval = $this->approve('pilot.business@example.com', Role::Business);
        RegistrationApproval::query()->whereKey($approval->id)->update([
            'status' => RegistrationApprovalStatus::Consumed->value,
            'consumed_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/register', $this->payload())->assertStatus(403);

        $this->assertNoRegistrationSideEffects();
    }

    public function test_second_registration_after_successful_use_is_rejected(): void
    {
        $this->enableAllowlist();
        $approval = $this->approve('pilot.business@example.com', Role::Business);

        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();
        $this->postJson('/api/v1/auth/register', $this->payload(['name' => 'Second Attempt']))
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);

        $this->assertSame(1, User::query()->where('email', 'pilot.business@example.com')->count());
        $this->assertSame(1, $approval->events()->where('action', RegistrationApprovalEventAction::Consumed)->count());
    }

    public function test_a_claim_lost_to_a_concurrent_registration_creates_nothing(): void
    {
        $this->enableAllowlist();
        $approval = $this->approve('pilot.business@example.com', Role::Business);

        // Simulate a competing request consuming the approval between this request's
        // locked read and its guarded status update.
        $raced = false;
        DB::connection()->beforeExecuting(function (string $query) use (&$raced, $approval): void {
            if ($raced || preg_match('/^update [`"]?registration_approvals[`"]? /i', $query) !== 1) {
                return;
            }

            $raced = true;
            DB::table('registration_approvals')->where('id', $approval->id)->update([
                'status' => RegistrationApprovalStatus::Consumed->value,
            ]);
        });

        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertStatus(403)
            ->assertJsonPath('error.message', RegistrationApprovalService::NOT_APPROVED_MESSAGE);

        $this->assertTrue($raced);
        $this->assertNoRegistrationSideEffects();
        $this->assertDatabaseCount('registration_approval_events', 1);
    }

    public function test_a_failure_after_claiming_rolls_back_the_consumption(): void
    {
        $this->enableAllowlist();
        $existing = User::factory()->business()->create(['email' => 'pilot.business@example.com']);

        $approval = new RegistrationApproval;
        $approval->email = 'pilot.business@example.com';
        $approval->active_email = 'pilot.business@example.com';
        $approval->role = Role::Business;
        $approval->status = RegistrationApprovalStatus::Pending;
        $approval->created_by_user_id = $this->superAdmin->id;
        $approval->save();

        try {
            app(AuthenticationService::class)->register('Pilot', 'pilot.business@example.com', 'password123', Role::Business);
            $this->fail('Expected the duplicate user insert to fail.');
        } catch (UniqueConstraintViolationException) {
            // The users.email unique index rejects the insert after the approval was claimed.
        }

        $approval->refresh();
        $this->assertSame(RegistrationApprovalStatus::Pending, $approval->status);
        $this->assertNull($approval->consumed_at);
        $this->assertNull($approval->consumed_by_user_id);
        $this->assertDatabaseCount('registration_approval_events', 0);
        $this->assertSame(1, User::query()->where('email', 'pilot.business@example.com')->count());
        $this->assertSame(0, $existing->tokens()->count());
        Notification::assertNothingSent();
    }

    public function test_existing_registration_rate_limit_still_applies_in_allowlist_mode(): void
    {
        $this->enableAllowlist();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/auth/register', $this->payload(['email' => "stranger{$attempt}@example.com"]))
                ->assertStatus(403);
        }

        $this->postJson('/api/v1/auth/register', $this->payload(['email' => 'stranger6@example.com']))
            ->assertStatus(429);
    }

    public function test_allowlisted_registration_keeps_the_email_verification_gate(): void
    {
        $this->enableAllowlist();
        $this->approve('pilot.ambassador@example.com', Role::Ambassador);

        $token = $this->postJson('/api/v1/auth/register', $this->payload([
            'email' => 'pilot.ambassador@example.com',
            'role' => 'AMBASSADOR',
        ]))->assertCreated()->json('data.token');

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email_verified_at', null);

        $this->withToken($token)->getJson('/api/v1/ambassadors/me')
            ->assertStatus(403)
            ->assertJsonPath('error.message', 'Please verify your email address to continue.');
    }

    private function enableAllowlist(): void
    {
        config(['registration.mode' => 'allowlist']);
    }

    private function approve(string $email, Role $role): RegistrationApproval
    {
        return app(RegistrationApprovalService::class)->create($this->superAdmin, $email, $role, null);
    }

    private function assertNoRegistrationSideEffects(): void
    {
        $this->assertSame(0, User::query()->where('role', '!=', Role::Admin->value)->count());
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseMissing('registration_approval_events', [
            'action' => RegistrationApprovalEventAction::Consumed->value,
        ]);
        $this->assertGuest('web');
        Notification::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Pilot Participant',
            'email' => 'pilot.business@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'BUSINESS',
        ], $overrides);
    }
}
