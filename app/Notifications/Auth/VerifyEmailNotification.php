<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

/**
 * Email-verification message (mail channel only).
 *
 * Intentionally does not extend BaseNotification: verification signatures must
 * not be stored in the in-app notifications table.
 */
class VerifyEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
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
        $verificationUrl = $this->verificationUrl($notifiable);
        $expireMinutes = (int) Config::get('auth.verification.expire', 60);

        return (new MailMessage)
            ->subject('Verify your MarcatursHub email address')
            ->line('Please click the button below to verify your email address.')
            ->action('Verify Email Address', $verificationUrl)
            ->line("This verification link will expire in {$expireMinutes} minutes.")
            ->line('If you did not create an account, no further action is required.');
    }

    protected function verificationUrl(mixed $notifiable): string
    {
        return URL::temporarySignedRoute(
            'api.v1.auth.email.verify',
            Carbon::now()->addMinutes((int) Config::get('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ],
        );
    }
}
