<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Option A — origin-bound Admin routes (MH-SEC-001).
 *
 * The session cookie is shared by app.* and admin.* (SESSION_DOMAIN), so an Admin
 * request made by a browser is only accepted when the browser says it came from the
 * Admin application. This applies to cookie and Bearer authentication alike: script
 * running on another origin must not be able to use a copied token to reach Admin
 * routes. Browsers always send Origin on cross-origin API calls, so a Bearer request
 * without one is a non-browser client (API tooling) and is not origin-bound.
 */
class EnsureAdminOrigin
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if (! $user->currentAccessToken() instanceof TransientToken && ! $request->headers->has('Origin')) {
            return $next($request);
        }

        $allowed = self::allowedOrigins();

        if ($allowed === [] && ! app()->isProduction()) {
            return $next($request);
        }

        $origin = self::normalize((string) $request->headers->get('Origin', ''));

        if ($origin === null || ! in_array($origin, $allowed, true)) {
            return ApiResponse::error(
                ApiErrorCode::FORBIDDEN,
                'You are not authorized to perform this action.',
                403,
            );
        }

        return $next($request);
    }

    /**
     * @return list<string>
     */
    public static function allowedOrigins(): array
    {
        $origins = [];

        foreach ((array) config('security.admin_origins', []) as $value) {
            $origin = self::normalize((string) $value);

            if ($origin !== null) {
                $origins[] = $origin;
            }
        }

        return array_values(array_unique($origins));
    }

    private static function normalize(string $value): ?string
    {
        $value = trim($value);

        if ($value === '' || $value === 'null' || $value === '*') {
            return null;
        }

        $parts = parse_url($value);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $origin = $scheme.'://'.strtolower($parts['host']);

        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }
}
