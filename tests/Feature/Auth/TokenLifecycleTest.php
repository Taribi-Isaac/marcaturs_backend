<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class TokenLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sanctum_expiration_is_configured_for_seven_days(): void
    {
        $this->assertSame(10080, (int) config('sanctum.expiration'));
    }

    public function test_login_creates_a_single_auth_pat_and_revokes_previous_pats(): void
    {
        $user = User::factory()->create([
            'email' => 'rotate@example.com',
            'password' => 'password',
        ]);

        $stale = $user->createToken('stale')->plainTextToken;
        $this->assertSame(1, $user->fresh()->tokens()->count());

        $firstLogin = $this->postJson('/api/v1/auth/login', [
            'email' => 'rotate@example.com',
            'password' => 'password',
        ])->assertOk()->json('data.token');

        $this->assertSame(1, $user->fresh()->tokens()->count());
        $this->assertSame('auth', $user->fresh()->tokens()->first()?->name);

        $this->app['auth']->forgetGuards();
        $this->withToken($stale)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);

        $this->app['auth']->forgetGuards();
        $this->withToken($firstLogin)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $secondLogin = $this->postJson('/api/v1/auth/login', [
            'email' => 'rotate@example.com',
            'password' => 'password',
        ])->assertOk()->json('data.token');

        $this->assertSame(1, $user->fresh()->tokens()->count());
        $this->assertNotSame($firstLogin, $secondLogin);

        $this->app['auth']->forgetGuards();
        $this->withToken($firstLogin)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);

        $this->app['auth']->forgetGuards();
        $this->withToken($secondLogin)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    public function test_failed_login_does_not_revoke_existing_pats(): void
    {
        $user = User::factory()->create([
            'email' => 'keep@example.com',
            'password' => 'password',
        ]);

        $existing = $this->postJson('/api/v1/auth/login', [
            'email' => 'keep@example.com',
            'password' => 'password',
        ])->assertOk()->json('data.token');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'keep@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(401);

        $this->assertSame(1, $user->fresh()->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($existing)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    public function test_repeated_login_does_not_accumulate_pats(): void
    {
        $user = User::factory()->create([
            'email' => 'repeat@example.com',
            'password' => 'password',
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'repeat@example.com',
                'password' => 'password',
            ])->assertOk();
        }

        $this->assertSame(1, $user->fresh()->tokens()->count());
    }

    public function test_registration_creates_exactly_one_auth_pat(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'New Biz',
            'email' => 'newbiz@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'BUSINESS',
        ])->assertCreated();

        $token = $response->json('data.token');
        $userId = (int) $response->json('data.user.id');
        $user = User::query()->findOrFail($userId);

        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame('auth', $user->tokens()->first()?->name);

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $userId);
    }

    public function test_expired_pat_is_rejected_while_fresh_pat_works(): void
    {
        Carbon::setTestNow('2026-09-18 12:00:00');

        $user = User::factory()->create([
            'email' => 'expiry@example.com',
            'password' => 'password',
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'expiry@example.com',
            'password' => 'password',
        ])->assertOk()->json('data.token');

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        Carbon::setTestNow(now()->addMinutes((int) config('sanctum.expiration') + 1));

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);

        $this->assertSame(1, $user->fresh()->tokens()->count());
    }

    public function test_password_reset_revokes_pats_and_deletes_database_sessions(): void
    {
        $user = User::factory()->create([
            'email' => 'reset-sessions@example.com',
            'password' => 'password',
        ]);

        $bearer = $this->postJson('/api/v1/auth/login', [
            'email' => 'reset-sessions@example.com',
            'password' => 'password',
        ])->assertOk()->json('data.token');

        DB::table('sessions')->insert([
            'id' => 'session-keep-other-user',
            'user_id' => User::factory()->create()->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => 'payload',
            'last_activity' => time(),
        ]);

        DB::table('sessions')->insert([
            [
                'id' => 'session-a-'.$user->id,
                'user_id' => $user->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'phpunit',
                'payload' => 'payload-a',
                'last_activity' => time(),
            ],
            [
                'id' => 'session-b-'.$user->id,
                'user_id' => $user->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'phpunit',
                'payload' => 'payload-b',
                'last_activity' => time(),
            ],
        ]);

        $resetToken = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'reset-sessions@example.com',
            'token' => $resetToken,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $this->assertSame(0, $user->fresh()->tokens()->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('id', 'session-keep-other-user')->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($bearer)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'reset-sessions@example.com',
            'password' => 'new-password-123',
        ])->assertOk();
    }

    public function test_concurrent_style_repeated_logins_leave_one_pat(): void
    {
        $user = User::factory()->create([
            'email' => 'parallel@example.com',
            'password' => 'password',
        ]);

        $tokens = [];
        foreach (range(1, 3) as $_) {
            $tokens[] = $this->postJson('/api/v1/auth/login', [
                'email' => 'parallel@example.com',
                'password' => 'password',
            ])->assertOk()->json('data.token');
        }

        $this->assertSame(1, $user->fresh()->tokens()->count());

        $winner = $tokens[array_key_last($tokens)];
        foreach ($tokens as $candidate) {
            $this->app['auth']->forgetGuards();
            $response = $this->withToken($candidate)->getJson('/api/v1/auth/me');
            if ($candidate === $winner) {
                $response->assertOk();
            } else {
                $response->assertStatus(401);
            }
        }
    }

    public function test_personal_access_token_records_use_auth_name_after_login(): void
    {
        $user = User::factory()->create([
            'email' => 'named@example.com',
            'password' => 'password',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'named@example.com',
            'password' => 'password',
        ])->assertOk();

        $token = PersonalAccessToken::query()->where('tokenable_id', $user->id)->sole();
        $this->assertSame('auth', $token->name);
        $this->assertSame(User::class, $token->tokenable_type);
    }
}
