<?php

namespace App\Support\Security;

use Illuminate\Support\Str;

/**
 * Resolves Reverb browser origin allowlist (MH-BE-050 / SEC-005).
 *
 * Laravel Reverb compares the WebSocket Origin *host* against each allowlist
 * entry via {@see Str::is()} (not full URLs). Entries may
 * be supplied as hosts (`localhost`), host patterns (`*.example.com`), or full
 * origins (`http://localhost:5180`) — full origins are normalized to hosts.
 *
 * Wildcard `*` is never accepted. Empty/missing configuration is fail-closed
 * outside local/testing (empty allowlist). Local/testing falls back to explicit
 * development hosts derived from FRONTEND_URL / ADMIN_URL when unset.
 */
final class ReverbAllowedOrigins
{
    /**
     * @return list<string>
     */
    public static function resolve(?string $raw = null, ?string $environment = null): array
    {
        // Prefer explicit args. Do not call app()->environment() from config files —
        // the container `env` binding is not available during LoadConfiguration.
        $environment ??= (string) (env('APP_ENV') ?: 'production');
        $configured = self::parseAndNormalize(
            $raw !== null ? $raw : env('REVERB_ALLOWED_ORIGINS'),
        );

        if ($configured !== []) {
            return $configured;
        }

        if (self::isLocalLike($environment)) {
            return self::localDevelopmentHosts();
        }

        // Staging / production / unknown: fail closed (no wildcard fallback).
        return [];
    }

    /**
     * Whether the resolved allowlist would accept a browser Origin header value
     * under Reverb's host-matching rules (mirrors Reverb Server::verifyOrigin).
     */
    public static function acceptsOrigin(?string $originHeader, array $allowedHosts): bool
    {
        if ($originHeader === null || $originHeader === '') {
            return false;
        }

        if (in_array('*', $allowedHosts, true)) {
            return false;
        }

        if ($allowedHosts === []) {
            return false;
        }

        $host = parse_url($originHeader, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        foreach ($allowedHosts as $allowed) {
            if (Str::is($allowed, $host)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function parseAndNormalize(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        $string = trim((string) $raw);
        if ($string === '') {
            return [];
        }

        $parts = array_map(
            static fn (string $part): string => trim($part),
            explode(',', $string),
        );

        $hosts = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '*') {
                continue;
            }

            $host = self::normalizeEntry($part);
            if ($host !== null && $host !== '' && $host !== '*') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    private static function normalizeEntry(string $entry): ?string
    {
        if (str_contains($entry, '://')) {
            $host = parse_url($entry, PHP_URL_HOST);

            return is_string($host) && $host !== '' ? $host : null;
        }

        // host:port → host (Reverb compares host only)
        if (preg_match('/^(\[[^\]]+\]|[^:]+):(\d+)$/', $entry, $matches) === 1) {
            return $matches[1];
        }

        return $entry;
    }

    /**
     * @return list<string>
     */
    private static function localDevelopmentHosts(): array
    {
        $hosts = ['localhost', '127.0.0.1', '::1'];

        foreach ([env('FRONTEND_URL'), env('ADMIN_URL')] as $url) {
            if (! is_string($url) || trim($url) === '') {
                continue;
            }
            $host = parse_url(trim($url), PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    private static function isLocalLike(string $environment): bool
    {
        return in_array($environment, ['local', 'testing'], true);
    }
}
