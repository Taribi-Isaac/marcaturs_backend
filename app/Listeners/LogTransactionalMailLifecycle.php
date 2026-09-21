<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Log;

/**
 * Safe transactional-mail observability (MH-GATE-007).
 *
 * Logs metadata only — never bodies, signed URLs, reset tokens, or SMTP secrets.
 */
final class LogTransactionalMailLifecycle
{
    public function handleNotificationSent(NotificationSent $event): void
    {
        if ($event->channel !== 'mail') {
            return;
        }

        Log::channel($this->channel())->info('transactional_mail.notification_sent', [
            'event' => 'notification_sent',
            'notification' => $event->notification::class,
            'channel' => $event->channel,
            'notifiable_type' => $event->notifiable::class,
            'notifiable_id' => $this->notifiableId($event->notifiable),
        ]);
    }

    public function handleNotificationFailed(NotificationFailed $event): void
    {
        Log::channel($this->channel())->warning('transactional_mail.notification_failed', [
            'event' => 'notification_failed',
            'notification' => $event->notification::class,
            'channel' => $event->channel,
            'notifiable_type' => $event->notifiable::class,
            'notifiable_id' => $this->notifiableId($event->notifiable),
            'error_category' => $event->exception::class,
        ]);
    }

    public function handleMessageSending(MessageSending $event): void
    {
        Log::channel($this->channel())->info('transactional_mail.message_sending', [
            'event' => 'message_sending',
            'mailer' => config('mail.default'),
            'to_count' => count($event->message->getTo()),
            'subject' => $event->message->getSubject(),
        ]);
    }

    public function handleMessageSent(MessageSent $event): void
    {
        Log::channel($this->channel())->info('transactional_mail.message_sent', [
            'event' => 'message_sent',
            'mailer' => config('mail.default'),
            'to_count' => count($event->message->getTo()),
            'subject' => $event->message->getSubject(),
        ]);
    }

    private function channel(): string
    {
        $configured = (string) config('mail.mailers.log.channel', 'mh_mail');

        return $configured !== '' ? $configured : 'mh_mail';
    }

    private function notifiableId(mixed $notifiable): int|string|null
    {
        if ($notifiable instanceof User) {
            return $notifiable->getKey();
        }

        if (is_object($notifiable) && method_exists($notifiable, 'getKey')) {
            return $notifiable->getKey();
        }

        return null;
    }
}
