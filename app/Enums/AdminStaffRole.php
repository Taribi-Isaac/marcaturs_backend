<?php

namespace App\Enums;

enum AdminStaffRole: string
{
    case SuperAdmin = 'SUPER_ADMIN';
    case Operations = 'OPERATIONS';
    case Verification = 'VERIFICATION';
    case Moderation = 'MODERATION';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
