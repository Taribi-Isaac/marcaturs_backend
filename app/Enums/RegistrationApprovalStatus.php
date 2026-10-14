<?php

namespace App\Enums;

enum RegistrationApprovalStatus: string
{
    case Pending = 'pending';
    case Consumed = 'consumed';
    case Revoked = 'revoked';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
