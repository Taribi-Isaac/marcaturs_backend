<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_unverified_user_and_sends_verification_notification(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Business',
            'email' => 'ada@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'BUSINESS',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.user.email_verified_at', null)
            ->assertJsonPath('data.token_type', 'Bearer');

        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertFalse($user->hasVerifiedEmail());

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_signed_verification_link_marks_email_verified(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute(
            'api.v1.auth.email.verify',
            now()->addMinutes(60),
            [
                'id' => $user->id,
                'hash' => sha1($user->email),
            ],
        );

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.message', 'Email verified successfully.')
            ->assertJsonPath('data.user.id', $user->id);

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_invalid_hash_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute(
            'api.v1.auth.email.verify',
            now()->addMinutes(60),
            [
                'id' => $user->id,
                'hash' => sha1('wrong@example.com'),
            ],
        );

        $this->getJson($url)
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_expired_verification_link_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        Carbon::setTestNow(now());

        $url = URL::temporarySignedRoute(
            'api.v1.auth.email.verify',
            now()->addMinutes(60),
            [
                'id' => $user->id,
                'hash' => sha1($user->email),
            ],
        );

        Carbon::setTestNow(now()->addMinutes(61));

        $this->getJson($url)
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);

        $this->assertNull($user->fresh()->email_verified_at);

        Carbon::setTestNow();
    }

    public function test_unsigned_verification_link_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $this->getJson("/api/v1/auth/email/verify/{$user->id}/".sha1($user->email))
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);
    }

    public function test_already_verified_link_is_idempotent(): void
    {
        $user = User::factory()->create();
        $this->assertNotNull($user->email_verified_at);

        $url = URL::temporarySignedRoute(
            'api.v1.auth.email.verify',
            now()->addMinutes(60),
            [
                'id' => $user->id,
                'hash' => sha1($user->email),
            ],
        );

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.message', 'Email verified successfully.');
    }

    public function test_verification_link_cannot_be_reused_after_email_change_hash_mismatch(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'old@example.com']);

        $url = URL::temporarySignedRoute(
            'api.v1.auth.email.verify',
            now()->addMinutes(60),
            [
                'id' => $user->id,
                'hash' => sha1('old@example.com'),
            ],
        );

        $user->forceFill(['email' => 'new@example.com'])->save();

        $this->getJson($url)
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);
    }

    public function test_resend_sends_notification_for_unverified_user(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('data.already_verified', false)
            ->assertJsonPath('data.message', 'A new verification link has been sent.');

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_resend_for_already_verified_user_does_not_send(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('data.already_verified', true);

        Notification::assertNothingSent();
    }

    public function test_resend_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/email/verification-notification')
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);
    }

    public function test_resend_rate_limiter_is_enforced(): void
    {
        config(['api.rate_limits.email_verification_per_minute' => 1]);
        RateLimiter::clear('email-verification');

        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);
        Notification::fake();

        $this->postJson('/api/v1/auth/email/verification-notification')->assertOk();

        $this->postJson('/api/v1/auth/email/verification-notification')
            ->assertStatus(429)
            ->assertJsonPath('error.code', ApiErrorCode::RATE_LIMITED);
    }

    public function test_restricted_user_can_resend_verification(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->restricted()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('data.already_verified', false);

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_suspended_user_cannot_resend_verification(): void
    {
        $user = User::factory()->unverified()->suspended()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/email/verification-notification')
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);
    }

    public function test_unverified_user_retains_authenticated_product_access(): void
    {
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email_verified_at', null);
    }

    public function test_verification_for_another_user_id_requires_matching_signed_hash(): void
    {
        $owner = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute(
            'api.v1.auth.email.verify',
            now()->addMinutes(60),
            [
                'id' => $other->id,
                'hash' => sha1($owner->email),
            ],
        );

        $this->getJson($url)
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);

        $this->assertNull($other->fresh()->email_verified_at);
        $this->assertNull($owner->fresh()->email_verified_at);
    }
}
