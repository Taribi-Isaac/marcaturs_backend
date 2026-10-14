<?php

namespace App\Enums;

enum RegistrationMode: string
{
    case Open = 'open';
    case Allowlist = 'allowlist';

    /**
     * Unknown or malformed configuration fails closed to the allow-list.
     */
    public static function current(): self
    {
        $configured = config('registration.mode');

        if (! is_string($configured)) {
            return self::Allowlist;
        }

        return self::tryFrom(strtolower(trim($configured))) ?? self::Allowlist;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
