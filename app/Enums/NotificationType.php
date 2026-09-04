<?php

namespace App\Enums;

/**
 * Stable notification type keys.
 *
 * Commission reminder types (MH-BE-022E) follow the approved MH-BE-022D contract.
 * Payment-pressure reminders are Business-only; status notifications may reach
 * Business and/or Ambassador according to the reminder engine.
 */
enum NotificationType: string
{
    case Test = 'test';

    case CommissionDue = 'commission_due';

    case CommissionPreDeadline = 'commission_pre_deadline';

    case CommissionDeadline = 'commission_deadline';

    case CommissionOverdue = 'commission_overdue';

    case CommissionOverdueFollowUp = 'commission_overdue_follow_up';

    case CommissionPaid = 'commission_paid';

    case CommissionReceived = 'commission_received';

    public function isBusinessPaymentPressureReminder(): bool
    {
        return match ($this) {
            self::CommissionPreDeadline,
            self::CommissionDeadline,
            self::CommissionOverdue,
            self::CommissionOverdueFollowUp => true,
            default => false,
        };
    }
}
