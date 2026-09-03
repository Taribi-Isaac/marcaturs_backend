<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LogoutAndCurrentUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_view_me_without_sensitive_fields(): void
    {
        $user = User::factory()->create();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json('data.token');

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token')
            ->assertJsonMissingPath('data.token');

        $payload = json_encode($this->withToken($token)->getJson('/api/v1/auth/me')->json());

        $this->assertStringNotContainsString('password', (string) $payload);
        $this->assertStringNotContainsString((string) $user->getRawOriginal('password'), (string) $payload);
    }

    public function test_unauthenticated_users_cannot_view_me(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);
    }

    public function test_authenticated_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json('data.token');

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(0, $user->fresh()->tokens()->count());

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_unauthenticated_logout_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/logout')
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);
    }

    public function test_repeated_logout_is_safe(): void
    {
        $user = User::factory()->create();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json('data.token');

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);

        $this->postJson('/api/v1/auth/logout')
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);
    }

    public function test_restricted_users_can_read_me_but_not_other_authenticated_routes(): void
    {
        $user = User::factory()->restricted()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.status', 'restricted');

        $this->app['router']->get('/api/v1/__auth/restricted-probe', fn () => ['ok' => true])
            ->middleware(['api', 'auth:sanctum', 'account.access'])
            ->name('api.v1.auth.restricted-probe');

        $this->getJson('/api/v1/__auth/restricted-probe')
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);
    }

    public function test_suspended_sessions_cannot_use_the_api_except_logout(): void
    {
        $user = User::factory()->suspended()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);

        $this->postJson('/api/v1/auth/logout')->assertOk();
    }
}
