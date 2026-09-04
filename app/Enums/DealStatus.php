<?php

namespace App\Enums;

enum DealStatus: string
{
    case PaymentPending = 'payment_pending';

    case Sealed = 'sealed';

    case Completed = 'completed';

    case Cancelled = 'cancelled';

    public function isSealed(): bool
    {
        return $this === self::Sealed;
    }

    public function isCompleted(): bool
    {
        return $this === self::Completed;
    }

    public function isCancelled(): bool
    {
        return $this === self::Cancelled;
    }

    public function allowsCancellation(): bool
    {
        return $this === self::PaymentPending;
    }

    public function allowsConfirmation(): bool
    {
        return $this === self::PaymentPending || $this === self::Sealed;
    }

    public function allowsRejection(): bool
    {
        return $this === self::PaymentPending;
    }
}
