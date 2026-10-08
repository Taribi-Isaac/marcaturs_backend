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
 * Option A — origin-bound Admin routes.
 *
 * The session cookie is shared by app.* and admin.* (SESSION_DOMAIN), so a
 * cookie-authenticated Admin request is only accepted when the browser says it
 * came from the Admin application. Personal access token requests carry no
 * ambient credentials and are not origin-bound.
 */
class EnsureAdminOrigin
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->currentAccessToken() instanceof TransientToken) {
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
