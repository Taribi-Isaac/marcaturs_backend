<?php

namespace App\Notifications\Channels;

use App\Notifications\BaseNotification;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Extends the default database channel to enforce idempotency.
 *
 * When a {@see BaseNotification} provides a non-null idempotency key,
 * this channel checks the notifications table for an existing record
 * with the same key before persisting. If a duplicate is found the
 * notification is silently skipped and logged.
 *
 * The UNIQUE constraint on `notifications.idempotency_key` acts as the
 * ultimate safety net for concurrent dispatch.
 */
class IdempotentDatabaseChannel extends DatabaseChannel
{
    /**
     * Send the given notification.
     */
    public function send(mixed $notifiable, Notification $notification): mixed
    {
        if ($notification instanceof BaseNotification) {
            $key = $notification->idempotencyKey();

            if ($key !== null) {
                $exists = $notifiable
                    ->notifications()
                    ->where('idempotency_key', $key)
                    ->exists();

                if ($exists) {
                    Log::info('Notification skipped (duplicate idempotency key)', [
                        'idempotency_key' => $key,
                        'notification_type' => $notification->notificationType()->value,
                    ]);

                    return null;
                }
            }
        }

        return parent::send($notifiable, $notification);
    }

    /**
     * Build the database notification array, injecting the idempotency key.
     *
     * @return array<string, mixed>
     */
    protected function buildPayload(mixed $notifiable, Notification $notification): array
    {
        $payload = parent::buildPayload($notifiable, $notification);

        if ($notification instanceof BaseNotification) {
            $payload['idempotency_key'] = $notification->idempotencyKey();
        }

        return $payload;
    }
}
