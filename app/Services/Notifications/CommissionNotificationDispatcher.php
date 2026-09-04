<?php

namespace App\Services\Notifications;

use App\Enums\NotificationType;
use App\Models\Commission;
use App\Models\User;
use App\Notifications\CommissionNotification;
use Illuminate\Support\Facades\Log;

/**
 * Dispatches Commission status and reminder notifications.
 *
 * Observational only — never mutates Deal or Commission financial state.
 */
class CommissionNotificationDispatcher
{
    public function notifyDue(Commission $commission): void
    {
        $scheduledDate = ($commission->became_due_at ?? now())->toDateString();

        $this->sendToUser(
            $commission->business_user_id,
            $commission,
            NotificationType::CommissionDue,
            $scheduledDate,
        );

        $this->sendToUser(
            $commission->ambassador_user_id,
            $commission,
            NotificationType::CommissionDue,
            $scheduledDate,
        );
    }

    public function notifyPaid(Commission $commission): void
    {
        $scheduledDate = ($commission->paid_at ?? now())->toDateString();

        $this->sendToUser(
            $commission->ambassador_user_id,
            $commission,
            NotificationType::CommissionPaid,
            $scheduledDate,
        );
    }

    public function notifyReceived(Commission $commission): void
    {
        $scheduledDate = ($commission->received_at ?? now())->toDateString();

        $this->sendToUser(
            $commission->business_user_id,
            $commission,
            NotificationType::CommissionReceived,
            $scheduledDate,
        );
    }

    public function notifyAmbassadorOverdueAwareness(Commission $commission): void
    {
        if (! $commission->status->isDue() || $commission->due_at === null) {
            return;
        }

        if (! now()->greaterThan($commission->due_at)) {
            return;
        }

        $scheduledDate = $commission->due_at->toDateString();

        $this->sendToUser(
            $commission->ambassador_user_id,
            $commission,
            NotificationType::CommissionOverdue,
            $scheduledDate,
        );
    }

    public function sendToUser(
        int $userId,
        Commission $commission,
        NotificationType $type,
        string $scheduledDate,
    ): void {
        $user = User::query()->find($userId);

        if ($user === null) {
            Log::warning('Commission notification skipped: recipient missing', [
                'commission_id' => $commission->id,
                'recipient_user_id' => $userId,
                'notification_type' => $type->value,
            ]);

            return;
        }

        // Transactional commission notifications are delivered regardless of
        // account status. API access remains separately gated by account.access.
        $user->notify(new CommissionNotification(
            $commission->id,
            $type,
            $scheduledDate,
            $user->id,
        ));
    }
}
