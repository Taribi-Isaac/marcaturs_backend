<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return ApiResponse::error(
                ApiErrorCode::UNAUTHENTICATED,
                'Authentication is required.',
                401,
            );
        }

        $allowed = array_map(static fn (string $role) => Role::from(strtoupper($role)), $roles);

        if (! in_array($user->role, $allowed, true)) {
            return ApiResponse::error(
                ApiErrorCode::FORBIDDEN,
                'You are not authorized to perform this action.',
                403,
            );
        }

        return $next($request);
    }
}
