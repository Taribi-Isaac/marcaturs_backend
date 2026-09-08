<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_admin_can_change_own_password(): void
    {
        $user = User::factory()->admin()->create([
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ])->assertOk()->json('data.token');

        $this->withToken($token)
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.message', 'Your password has been changed.')
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.current_password');

        $user->refresh();
        $this->assertTrue(Hash::check('new-password-123', $user->password));
        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertFalse(str_contains($user->password, 'new-password-123'));

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_business_and_ambassador_can_change_own_password(): void
    {
        foreach ([
            User::factory()->business()->create(['password' => 'password']),
            User::factory()->ambassador()->create(['password' => 'password']),
        ] as $user) {
            Sanctum::actingAs($user);

            $this->postJson('/api/v1/auth/change-password', [
                'current_password' => 'password',
                'password' => 'role-password-123',
                'password_confirmation' => 'role-password-123',
            ])->assertOk();

            $this->assertTrue(Hash::check('role-password-123', $user->fresh()->password));
        }
    }

    public function test_unauthenticated_change_password_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);
    }

    public function test_incorrect_current_password_is_rejected_and_leaves_password_unchanged(): void
    {
        $user = User::factory()->admin()->create(['password' => 'password']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_password_confirmation_mismatch_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create(['password' => 'password']));

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password',
            'password' => 'new-password-123',
            'password_confirmation' => 'different-password-123',
        ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);
    }

    public function test_password_policy_is_enforced(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create(['password' => 'password']));

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);
    }

    public function test_new_password_must_differ_from_current_password(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create(['password' => 'password']));

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);
    }

    public function test_other_sanctum_tokens_are_revoked_while_current_token_remains(): void
    {
        $user = User::factory()->admin()->create([
            'email' => 'admin-tokens@example.com',
            'password' => 'password',
        ]);

        $currentToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin-tokens@example.com',
            'password' => 'password',
        ])->assertOk()->json('data.token');

        $otherToken = $user->createToken('other-device')->plainTextToken;
        $this->assertSame(2, $user->fresh()->tokens()->count());

        $this->withToken($currentToken)
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertOk();

        $this->assertSame(1, $user->fresh()->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($currentToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($otherToken)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }

    public function test_old_password_stops_working_and_new_password_logs_in(): void
    {
        $user = User::factory()->admin()->create([
            'email' => 'admin-login@example.com',
            'password' => 'password',
        ]);

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin-login@example.com',
            'password' => 'password',
        ])->assertStatus(401);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin-login@example.com',
            'password' => 'new-password-123',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);
    }

    public function test_restricted_user_can_change_password_but_suspended_cannot(): void
    {
        $restricted = User::factory()->admin()->restricted()->create(['password' => 'password']);
        Sanctum::actingAs($restricted);
        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password',
            'password' => 'restricted-pass-123',
            'password_confirmation' => 'restricted-pass-123',
        ])->assertOk();

        $suspended = User::factory()->admin()->suspended()->create(['password' => 'password']);
        Sanctum::actingAs($suspended);
        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password',
            'password' => 'suspended-pass-123',
            'password_confirmation' => 'suspended-pass-123',
        ])->assertStatus(403);
    }

    public function test_change_password_is_rate_limited(): void
    {
        config(['api.rate_limits.change_password_per_minute' => 1]);
        RateLimiter::clear('change-password');

        Sanctum::actingAs(User::factory()->admin()->create(['password' => 'password']));

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'wrong',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(400);

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'wrong',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])
            ->assertStatus(429)
            ->assertJsonPath('error.code', ApiErrorCode::RATE_LIMITED);
    }

    public function test_endpoint_does_not_accept_user_id_target(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'password']);
        $other = User::factory()->admin()->create(['password' => 'password']);

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/auth/change-password', [
            'user_id' => $other->id,
            'current_password' => 'password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password-123', $admin->fresh()->password));
        $this->assertTrue(Hash::check('password', $other->fresh()->password));
    }
}
