<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Password-reset email (mail channel only).
 *
 * Intentionally does not extend BaseNotification: reset tokens must never be
 * persisted to the in-app notifications table or logged as structured payload.
 */
class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $token)
    {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $email = method_exists($notifiable, 'getEmailForPasswordReset')
            ? $notifiable->getEmailForPasswordReset()
            : (string) $notifiable->email;

        $base = rtrim((string) env('FRONTEND_URL', ''), '/');
        $url = $base.'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $email,
        ]);

        $expireMinutes = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Reset your MarcatursHub password')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset Password', $url)
            ->line("This password reset link will expire in {$expireMinutes} minutes.")
            ->line('If you did not request a password reset, no further action is required.');
    }
}
