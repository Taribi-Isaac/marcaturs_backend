<?php

namespace App\Services\Notifications;

use App\Enums\NotificationType;
use App\Models\Dispute;
use App\Models\User;
use App\Notifications\DisputeNotification;
use Illuminate\Support\Facades\Log;

/**
 * Dispatches Dispute case notifications.
 *
 * Observational only — never mutates Deal, Commission, or Dispute financial fields.
 */
class DisputeNotificationDispatcher
{
    public function notifyOpened(Dispute $dispute): void
    {
        $this->sendToUser(
            $dispute->accused_user_id,
            $dispute,
            NotificationType::DisputeOpened,
        );
    }

    public function notifyResolved(Dispute $dispute): void
    {
        $this->sendToUser(
            $dispute->reporter_user_id,
            $dispute,
            NotificationType::DisputeResolved,
        );

        $this->sendToUser(
            $dispute->accused_user_id,
            $dispute,
            NotificationType::DisputeResolved,
        );
    }

    private function sendToUser(int $userId, Dispute $dispute, NotificationType $type): void
    {
        $user = User::query()->find($userId);

        if ($user === null) {
            Log::warning('Dispute notification skipped: recipient missing', [
                'dispute_id' => $dispute->id,
                'recipient_user_id' => $userId,
                'notification_type' => $type->value,
            ]);

            return;
        }

        $user->notify(new DisputeNotification(
            $dispute->id,
            $type,
            $user->id,
        ));
    }
}
