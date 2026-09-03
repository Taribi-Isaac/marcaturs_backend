<?php

namespace App\Enums;

enum AccountStatus: string
{
    case Active = 'active';
    case Restricted = 'restricted';
    case Suspended = 'suspended';
    case Banned = 'banned';

    public function canAuthenticate(): bool
    {
        return $this === self::Active || $this === self::Restricted;
    }

    public function isRestricted(): bool
    {
        return $this === self::Restricted;
    }

    public function isBlocked(): bool
    {
        return $this === self::Suspended || $this === self::Banned;
    }
}
