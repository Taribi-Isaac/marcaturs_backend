<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Reusable base notification for MarcatursHub.
 *
 * Every platform notification should extend this class and implement:
 *  - notificationType()  → the stable {@see NotificationType} key
 *  - toMail()            → email representation (or null to skip email)
 *  - payload()           → structured data stored for the in-app channel
 *  - idempotencyKey()    → deterministic key to prevent duplicate delivery
 *                           (null allows unrestricted delivery)
 *
 * Channels: mail + database (in-app).
 * SMS/WhatsApp/Push are future additions and must not be added here.
 */
abstract class BaseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The queue connection and queue name may be overridden per notification
     * if finer-grained queue routing is needed in the future.
     */
    public function __construct()
    {
        $this->afterCommit();
    }

    /**
     * Stable notification type key for persistence and frontend routing.
     */
    abstract public function notificationType(): NotificationType;

    /**
     * Build the mail representation, or return null to skip the email channel.
     */
    abstract public function toMail(mixed $notifiable): ?MailMessage;

    /**
     * Structured payload for the in-app (database) channel.
     *
     * Must NOT include passwords, tokens, bank account secrets,
     * payment evidence contents, or sensitive verification data.
     *
     * @return array<string, mixed>
     */
    abstract protected function payload(): array;

    /**
     * A deterministic idempotency key that prevents duplicate persistence.
     *
     * Return null to allow unrestricted delivery (no deduplication).
     * When non-null, the notifications table UNIQUE constraint on
     * idempotency_key will prevent a second insert for the same key.
     */
    public function idempotencyKey(): ?string
    {
        return null;
    }

    // ─────────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        $channels = ['database'];

        if ($this->toMail($notifiable) !== null) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * Data persisted in the notifications table (database channel).
     *
     * @return array<string, mixed>
     */
    public function toDatabase(mixed $notifiable): array
    {
        return array_merge($this->payload(), [
            'notification_type' => $this->notificationType()->value,
            'idempotency_key' => $this->idempotencyKey(),
        ]);
    }
}
