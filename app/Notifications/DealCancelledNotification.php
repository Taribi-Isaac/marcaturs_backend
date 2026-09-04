<?php

namespace App\Notifications;

use App\Enums\DealStatus;
use App\Enums\NotificationType;
use App\Models\Deal;
use Illuminate\Notifications\Messages\MailMessage;

class DealCancelledNotification extends BaseNotification
{
    public function __construct(
        private readonly int $dealId,
        private readonly int $recipientUserId,
    ) {
        parent::__construct();
    }

    public function notificationType(): NotificationType
    {
        return NotificationType::DealCancelled;
    }

    public function idempotencyKey(): ?string
    {
        return sprintf(
            'deal:%d:%s:%d',
            $this->dealId,
            NotificationType::DealCancelled->value,
            $this->recipientUserId,
        );
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        unset($channel);

        $deal = Deal::query()->find($this->dealId);

        return $deal !== null && $deal->status === DealStatus::Cancelled;
    }

    public function toMail(mixed $notifiable): ?MailMessage
    {
        $deal = $this->loadDeal();

        return (new MailMessage)
            ->subject('MarcatursHub: Deal cancelled')
            ->line('Deal #'.$deal->id.' has been cancelled.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        $deal = $this->loadDeal();

        return [
            'deal_id' => $deal->id,
            'status' => $deal->status->value,
            'campaign_id' => $deal->campaign_id,
            'cancelled_at' => $deal->cancelled_at?->toIso8601String(),
        ];
    }

    private function loadDeal(): Deal
    {
        return Deal::query()->findOrFail($this->dealId);
    }
}
