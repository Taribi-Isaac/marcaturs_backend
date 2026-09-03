<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'ADMIN';
    case Business = 'BUSINESS';
    case Ambassador = 'AMBASSADOR';

    /**
     * @return list<string>
     */
    public static function publicRegistrationValues(): array
    {
        return [
            self::Business->value,
            self::Ambassador->value,
        ];
    }

    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }
}
