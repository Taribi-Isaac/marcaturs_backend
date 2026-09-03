<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Foundation test notification.
 *
 * Used only in automated tests to validate the notification pipeline.
 * This is NOT a production notification type and must not be dispatched
 * by application code outside of tests.
 */
class TestNotification extends BaseNotification
{
    public function __construct(
        private readonly string $title = 'Test notification',
        private readonly ?string $idempotency = null,
        private readonly bool $sendEmail = true,
    ) {
        parent::__construct();
    }

    public function notificationType(): NotificationType
    {
        return NotificationType::Test;
    }

    public function toMail(mixed $notifiable): ?MailMessage
    {
        if (! $this->sendEmail) {
            return null;
        }

        return (new MailMessage)
            ->subject('MarcatursHub: '.$this->title)
            ->line($this->title)
            ->line('This is a test notification.');
    }

    protected function payload(): array
    {
        return [
            'title' => $this->title,
        ];
    }

    public function idempotencyKey(): ?string
    {
        return $this->idempotency;
    }
}
