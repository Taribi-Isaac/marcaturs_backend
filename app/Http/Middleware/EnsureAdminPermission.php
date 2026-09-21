<?php

namespace App\Http\Middleware;

use App\Enums\AdminPermission;
use App\Models\User;
use App\Services\Admin\AdminAuthorization;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use ValueError;

class EnsureAdminPermission
{
    public function __construct(
        private readonly AdminAuthorization $authorization,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return ApiResponse::error(
                ApiErrorCode::UNAUTHENTICATED,
                'Authentication is required.',
                401,
            );
        }

        try {
            $required = AdminPermission::from($permission);
        } catch (ValueError) {
            return ApiResponse::error(
                ApiErrorCode::FORBIDDEN,
                'You are not authorized to perform this action.',
                403,
            );
        }

        if (! $this->authorization->allows($user, $required)) {
            return ApiResponse::error(
                ApiErrorCode::FORBIDDEN,
                'You are not authorized to perform this action.',
                403,
            );
        }

        return $next($request);
    }
}
