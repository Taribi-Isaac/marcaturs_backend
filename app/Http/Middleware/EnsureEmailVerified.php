<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Participants must verify email before using authenticated product APIs.
 * Admin staff are exempt (Admin Control uses the same Sanctum stack).
 * Email verification remains distinct from participant Verification checklists.
 */
class EnsureEmailVerified
{
    /**
     * @var list<string>
     */
    private const ALLOWED_ROUTES = [
        'api.v1.auth.me',
        'api.v1.auth.logout',
        'api.v1.auth.change-password',
        'api.v1.auth.email.verification-notification',
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->isAdmin() || $user->hasVerifiedEmail()) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        if (in_array($routeName, self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        return ApiResponse::error(
            ApiErrorCode::FORBIDDEN,
            'Please verify your email address to continue.',
            403,
        );
    }
}
