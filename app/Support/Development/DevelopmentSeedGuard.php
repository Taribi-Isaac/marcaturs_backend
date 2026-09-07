<?php

namespace App\Support\Development;

use RuntimeException;

/**
 * Prevents accidental demo/UAT seeding against production databases.
 */
final class DevelopmentSeedGuard
{
    /**
     * @var list<string>
     */
    private const ALLOWED_ENVIRONMENTS = ['local', 'testing'];

    public static function ensureAllowed(bool $force = false): void
    {
        $env = (string) app()->environment();

        if (in_array($env, self::ALLOWED_ENVIRONMENTS, true)) {
            return;
        }

        if (
            $env === 'staging'
            && $force
            && filter_var(env('ALLOW_DEMO_SEED', false), FILTER_VALIDATE_BOOLEAN)
        ) {
            return;
        }

        throw new RuntimeException(
            'Development/UAT demo seed is blocked in this environment ('.$env.'). '
            .'Allowed: local, testing. Staging requires --force and ALLOW_DEMO_SEED=true. '
            .'Never run against production.',
        );
    }

    public static function demoEmailDomain(): string
    {
        return 'demo.marcaturshub.test';
    }

    public static function isDemoEmail(string $email): bool
    {
        return str_ends_with(strtolower($email), '@'.self::demoEmailDomain());
    }
}
