<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_sends_reset_notification_for_registered_email(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'biz@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'biz@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.message', 'If that email address is registered, a password reset link has been sent.')
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.reset_token');

        Notification::assertSentTo($user, ResetPasswordNotification::class);
        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'biz@example.com',
        ]);
    }

    public function test_forgot_password_is_enumeration_safe_for_unknown_email(): void
    {
        Notification::fake();

        $known = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'missing@example.com',
        ]);

        User::factory()->create(['email' => 'biz@example.com']);

        $unknownStyle = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'also-missing@example.com',
        ]);

        $known->assertOk()->assertJsonPath('data.message', 'If that email address is registered, a password reset link has been sent.');
        $unknownStyle->assertOk()->assertJsonPath('data.message', $known->json('data.message'));
        Notification::assertNothingSent();
    }

    public function test_forgot_password_validates_email(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'not-an-email',
        ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'biz@example.com',
            'password' => 'password',
        ]);

        $token = Password::broker()->createToken($user);

        $loginToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'biz@example.com',
            'password' => 'password',
        ])->json('data.token');

        $this->assertNotEmpty($loginToken);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'biz@example.com',
            'token' => $token,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.message', 'Your password has been reset.')
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.password');

        $user->refresh();
        $this->assertTrue(Hash::check('new-password-123', $user->password));
        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertSame(0, $user->tokens()->count());

        $this->postJson('/api/v1/auth/login', [
            'email' => 'biz@example.com',
            'password' => 'password',
        ])->assertStatus(401);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'biz@example.com',
            'password' => 'new-password-123',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);

        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'biz@example.com',
        ]);
    }

    public function test_reset_rejects_invalid_token(): void
    {
        User::factory()->create(['email' => 'biz@example.com']);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'biz@example.com',
            'token' => 'not-a-real-token',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ApiErrorCode::BUSINESS_VALIDATION)
            ->assertJsonPath('error.message', 'This password reset token is invalid.');
    }

    public function test_reset_token_cannot_be_reused(): void
    {
        $user = User::factory()->create(['email' => 'biz@example.com']);
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'biz@example.com',
            'token' => $token,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'biz@example.com',
            'token' => $token,
            'password' => 'another-password-123',
            'password_confirmation' => 'another-password-123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ApiErrorCode::BUSINESS_VALIDATION);
    }

    public function test_expired_reset_token_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'biz@example.com']);
        $token = Password::broker()->createToken($user);

        DB::table('password_reset_tokens')
            ->where('email', 'biz@example.com')
            ->update(['created_at' => now()->subMinutes(61)]);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'biz@example.com',
            'token' => $token,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ApiErrorCode::BUSINESS_VALIDATION);
    }

    public function test_reset_rejects_short_password(): void
    {
        $user = User::factory()->create(['email' => 'biz@example.com']);
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'biz@example.com',
            'token' => $token,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);
    }

    public function test_password_reset_rate_limiter_is_enforced(): void
    {
        config(['api.rate_limits.password_reset_per_minute' => 1]);
        RateLimiter::clear('password-reset');

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'nobody@example.com',
        ])->assertOk();

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'nobody@example.com',
        ])
            ->assertStatus(429)
            ->assertJsonPath('error.code', ApiErrorCode::RATE_LIMITED);
    }

    public function test_reset_preserves_account_status_and_does_not_restore_blocked_login(): void
    {
        $suspended = User::factory()->suspended()->create([
            'email' => 'suspended@example.com',
            'password' => 'password',
        ]);
        $token = Password::broker()->createToken($suspended);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'suspended@example.com',
            'token' => $token,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $this->assertSame('suspended', $suspended->fresh()->status->value);
        $this->assertTrue(Hash::check('new-password-123', $suspended->fresh()->password));

        $this->postJson('/api/v1/auth/login', [
            'email' => 'suspended@example.com',
            'password' => 'new-password-123',
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);
    }

    public function test_reset_notification_does_not_expose_token_in_api_response_body_keys(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'biz@example.com']);

        $payload = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'biz@example.com',
        ])->json();

        $encoded = json_encode($payload);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('"token"', $encoded);
        $this->assertStringNotContainsString('password_reset', $encoded);
    }

    public function test_restricted_and_active_accounts_can_request_reset(): void
    {
        Notification::fake();

        $active = User::factory()->create(['email' => 'active@example.com']);
        $restricted = User::factory()->restricted()->create(['email' => 'restricted@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $active->email])->assertOk();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $restricted->email])->assertOk();

        Notification::assertSentTo($active, ResetPasswordNotification::class);
        Notification::assertSentTo($restricted, ResetPasswordNotification::class);
    }

    public function test_admin_can_use_the_existing_password_reset_flow(): void
    {
        Notification::fake();
        config(['app.frontend_url' => 'https://app.example.test']);

        $admin = User::factory()->admin()->create([
            'email' => 'admin-s03@example.com',
            'password' => 'password123',
        ]);

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'admin-s03@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('data.message', 'If that email address is registered, a password reset link has been sent.')
            ->assertJsonMissingPath('data.token');

        $token = null;
        Notification::assertSentTo(
            $admin,
            ResetPasswordNotification::class,
            function (ResetPasswordNotification $notification) use ($admin, &$token): bool {
                $token = $notification->token;
                $mail = $notification->toMail($admin);

                return str_starts_with((string) $mail->actionUrl, 'https://app.example.test/reset-password?')
                    && str_contains((string) $mail->actionUrl, 'token='.urlencode((string) $notification->token))
                    && str_contains((string) $mail->actionUrl, 'email=admin-s03%40example.com');
            },
        );
        $this->assertIsString($token);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'admin-s03@example.com',
            'token' => $token,
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])
            ->assertOk()
            ->assertJsonPath('data.message', 'Your password has been reset.')
            ->assertJsonPath('data.user.role', 'ADMIN')
            ->assertJsonMissingPath('data.token');

        $admin->refresh();
        $this->assertSame('ADMIN', $admin->role->value);
        $this->assertSame('active', $admin->status->value);
        $this->assertTrue(Hash::check('new-password-456', $admin->password));
        $this->assertFalse(Hash::check('password123', $admin->password));

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin-s03@example.com',
            'password' => 'password123',
        ])->assertStatus(401);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin-s03@example.com',
            'password' => 'new-password-456',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'ADMIN')
            ->assertJsonMissingPath('data.token');
    }
}
