<?php

namespace App\Enums;

enum CommissionStatus: string
{
    case Due = 'due';

    case Paid = 'paid';

    case Received = 'received';

    public function isDue(): bool
    {
        return $this === self::Due;
    }

    public function isPaid(): bool
    {
        return $this === self::Paid;
    }

    public function isReceived(): bool
    {
        return $this === self::Received;
    }
}
