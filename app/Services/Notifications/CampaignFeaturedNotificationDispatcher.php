<?php

namespace App\Services\Notifications;

use App\Models\CampaignFeaturedPurchase;
use App\Models\User;
use App\Notifications\CampaignFeaturedPurchasedNotification;
use Illuminate\Support\Facades\Log;

class CampaignFeaturedNotificationDispatcher
{
    public function notifyPurchased(CampaignFeaturedPurchase $purchase): void
    {
        $recipient = User::query()->find($purchase->user_id);

        if ($recipient === null) {
            Log::warning('Featured purchase notification skipped: recipient missing', [
                'purchase_id' => $purchase->id,
                'user_id' => $purchase->user_id,
            ]);

            return;
        }

        $recipient->notify(new CampaignFeaturedPurchasedNotification(
            $purchase->id,
            $recipient->id,
        ));
    }
}
