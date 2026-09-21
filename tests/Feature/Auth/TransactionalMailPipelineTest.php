<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * MH-GATE-007 — queued mail pipeline.
 *
 * phpunit.xml uses QUEUE_CONNECTION=sync and MAIL_MAILER=array so workers are
 * not required here. Local development uses Redis + MAIL_MAILER=log and needs
 * `php artisan queue:work` before rendered messages appear in storage/logs/mail.log.
 */
class TransactionalMailPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_resend_dispatches_queued_mail_notification(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->ambassador()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('data.already_verified', false);

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_verification_mail_is_delivered_when_queue_is_sync(): void
    {
        Event::fake([MessageSent::class, NotificationSent::class]);

        $user = User::factory()->unverified()->ambassador()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('data.already_verified', false);

        Event::assertDispatched(
            NotificationSent::class,
            fn (NotificationSent $event): bool => $event->notification instanceof VerifyEmailNotification
                && $event->channel === 'mail',
        );
        Event::assertDispatched(MessageSent::class);
    }

    public function test_password_reset_request_dispatches_mail_without_enumerating_accounts(): void
    {
        Notification::fake();

        $user = User::factory()->ambassador()->create([
            'email' => 'reset.target@example.test',
        ]);

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'reset.target@example.test',
        ])->assertOk();

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'unknown@example.test',
        ])->assertOk();

        Notification::assertSentTo($user, ResetPasswordNotification::class);
        Notification::assertSentTimes(ResetPasswordNotification::class, 1);
    }

    public function test_password_reset_mail_is_delivered_when_queue_is_sync(): void
    {
        Event::fake([MessageSent::class, NotificationSent::class]);

        $user = User::factory()->ambassador()->create([
            'email' => 'reset.mail@example.test',
        ]);

        $status = Password::broker()->sendResetLink(['email' => $user->email]);
        $this->assertSame(Password::RESET_LINK_SENT, $status);

        Event::assertDispatched(
            NotificationSent::class,
            fn (NotificationSent $event): bool => $event->notification instanceof ResetPasswordNotification
                && $event->channel === 'mail',
        );
        Event::assertDispatched(MessageSent::class);
    }
}
