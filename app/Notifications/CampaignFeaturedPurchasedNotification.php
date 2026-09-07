<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\CampaignFeaturedPurchase;
use Illuminate\Notifications\Messages\MailMessage;

class CampaignFeaturedPurchasedNotification extends BaseNotification
{
    public function __construct(
        private readonly int $purchaseId,
        private readonly int $recipientUserId,
    ) {
        parent::__construct();
    }

    public function notificationType(): NotificationType
    {
        return NotificationType::CampaignFeaturedPurchased;
    }

    public function idempotencyKey(): ?string
    {
        return sprintf(
            'campaign_featured:%d:%s:%d',
            $this->purchaseId,
            NotificationType::CampaignFeaturedPurchased->value,
            $this->recipientUserId,
        );
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        unset($channel);

        return CampaignFeaturedPurchase::query()->whereKey($this->purchaseId)->exists();
    }

    public function toMail(mixed $notifiable): ?MailMessage
    {
        $purchase = $this->loadPurchase();

        return (new MailMessage)
            ->subject('MarcatursHub: Featured campaign activated')
            ->line('Featured visibility is now active for your campaign.')
            ->line('Package: '.$purchase->package_name)
            ->line('Expires: '.$purchase->expires_at?->toDayDateTimeString());
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        $purchase = $this->loadPurchase();

        return [
            'campaign_id' => $purchase->campaign_id,
            'purchase_id' => $purchase->id,
            'package_name' => $purchase->package_name,
            'duration_days' => $purchase->duration_days,
            'activated_at' => $purchase->activated_at?->toIso8601String(),
            'expires_at' => $purchase->expires_at?->toIso8601String(),
        ];
    }

    private function loadPurchase(): CampaignFeaturedPurchase
    {
        return CampaignFeaturedPurchase::query()->findOrFail($this->purchaseId);
    }
}
