<?php

namespace App\Enums;

enum DealStatus: string
{
    case PaymentPending = 'payment_pending';

    case Sealed = 'sealed';

    public function isSealed(): bool
    {
        return $this === self::Sealed;
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
