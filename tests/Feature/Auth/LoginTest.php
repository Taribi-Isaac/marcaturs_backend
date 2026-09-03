<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'biz@example.com',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'biz@example.com',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.role', Role::Business->value)
            ->assertJsonMissingPath('data.user.password');

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_login_rejects_invalid_credentials_without_revealing_whether_the_email_exists(): void
    {
        User::factory()->create(['email' => 'biz@example.com']);

        $unknown = $this->postJson('/api/v1/auth/login', [
            'email' => 'missing@example.com',
            'password' => 'password',
        ]);

        $wrong = $this->postJson('/api/v1/auth/login', [
            'email' => 'biz@example.com',
            'password' => 'incorrect-password',
        ]);

        $unknown->assertStatus(401)->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);
        $wrong->assertStatus(401)->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);
        $this->assertSame($unknown->json('error.message'), $wrong->json('error.message'));
    }

    public function test_suspended_and_banned_accounts_cannot_login(): void
    {
        $suspended = User::factory()->suspended()->create(['email' => 'suspended@example.com']);
        $banned = User::factory()->banned()->create(['email' => 'banned@example.com']);

        $this->postJson('/api/v1/auth/login', [
            'email' => $suspended->email,
            'password' => 'password',
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);

        $this->postJson('/api/v1/auth/login', [
            'email' => $banned->email,
            'password' => 'password',
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);
    }

    public function test_restricted_accounts_can_login(): void
    {
        User::factory()->restricted()->create(['email' => 'limited@example.com']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'limited@example.com',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.status', 'restricted');
    }

    public function test_login_rate_limiter_is_enforced(): void
    {
        config(['api.rate_limits.login_per_minute' => 1]);
        RateLimiter::clear('login');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'password',
        ])->assertStatus(401);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'password',
        ])
            ->assertStatus(429)
            ->assertJsonPath('error.code', ApiErrorCode::RATE_LIMITED);
    }

    public function test_registration_rate_limiter_is_enforced(): void
    {
        config(['api.rate_limits.registration_per_minute' => 1]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'One',
            'email' => 'one@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'BUSINESS',
        ])->assertCreated();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Two',
            'email' => 'two@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'AMBASSADOR',
        ])
            ->assertStatus(429)
            ->assertJsonPath('error.code', ApiErrorCode::RATE_LIMITED);
    }
}
