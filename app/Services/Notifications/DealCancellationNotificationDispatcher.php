<?php

namespace App\Services\Notifications;

use App\Models\Deal;
use App\Models\User;
use App\Notifications\DealCancelledNotification;
use Illuminate\Support\Facades\Log;

/**
 * Dispatches Deal cancellation notifications to both Deal parties.
 *
 * Observational only — never mutates Deal or Commission financial fields.
 */
class DealCancellationNotificationDispatcher
{
    public function notifyCancelled(Deal $deal): void
    {
        $this->sendToUser($deal->business_user_id, $deal);
        $this->sendToUser($deal->ambassador_user_id, $deal);
    }

    private function sendToUser(int $userId, Deal $deal): void
    {
        $user = User::query()->find($userId);

        if ($user === null) {
            Log::warning('Deal cancellation notification skipped: recipient missing', [
                'deal_id' => $deal->id,
                'recipient_user_id' => $userId,
            ]);

            return;
        }

        $user->notify(new DealCancelledNotification(
            $deal->id,
            $user->id,
        ));
    }
}
