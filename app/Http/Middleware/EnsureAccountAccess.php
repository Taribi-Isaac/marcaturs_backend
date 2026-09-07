<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountAccess
{
    /**
     * Identity endpoints a restricted account may still call.
     *
     * @var list<string>
     */
    private const RESTRICTED_ALLOWED_ROUTES = [
        'api.v1.auth.me',
        'api.v1.auth.logout',
        'api.v1.auth.email.verification-notification',
    ];

    /**
     * @var list<string>
     */
    private const BLOCKED_ALLOWED_ROUTES = [
        'api.v1.auth.logout',
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        if ($user->status->isBlocked() && ! in_array($routeName, self::BLOCKED_ALLOWED_ROUTES, true)) {
            return ApiResponse::error(
                ApiErrorCode::FORBIDDEN,
                'This account is not permitted to access the platform.',
                403,
            );
        }

        if ($user->status->isRestricted() && ! in_array($routeName, self::RESTRICTED_ALLOWED_ROUTES, true)) {
            return ApiResponse::error(
                ApiErrorCode::FORBIDDEN,
                'This account is restricted.',
                403,
            );
        }

        return $next($request);
    }
}
