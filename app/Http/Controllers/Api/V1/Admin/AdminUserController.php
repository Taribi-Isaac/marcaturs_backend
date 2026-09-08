<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Users\AdminUserIndexRequest;
use App\Http\Requests\Api\V1\Admin\Users\AdminUserStatusActionRequest;
use App\Http\Resources\Api\V1\AdminUserResource;
use App\Services\Admin\AdminUserService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function __construct(
        private readonly AdminUserService $users,
    ) {}

    public function index(AdminUserIndexRequest $request): JsonResponse
    {
        $role = $request->filled('role')
            ? Role::from($request->string('role')->toString())
            : null;

        $statusValue = $request->validated('status');
        $status = is_string($statusValue) ? AccountStatus::from($statusValue) : $statusValue;

        $paginator = $this->users->list(
            $role,
            $status,
            $request->input('q'),
            $this->perPage($request),
        );

        return ApiResponse::paginated(
            $paginator,
            collect($paginator->items())->map(
                fn ($user) => (new AdminUserResource($user))->resolve($request),
            )->all(),
        );
    }

    public function show(Request $request, int $user): JsonResponse
    {
        return ApiResponse::success(
            (new AdminUserResource($this->users->show($user), true))->resolve($request),
        );
    }

    public function restrict(AdminUserStatusActionRequest $request, int $user): JsonResponse
    {
        $updated = $this->users->restrict(
            $request->user(),
            $user,
            $request->string('reason')->toString(),
        );

        return ApiResponse::success(
            (new AdminUserResource($updated, true))->resolve($request),
        );
    }

    public function suspend(AdminUserStatusActionRequest $request, int $user): JsonResponse
    {
        $updated = $this->users->suspend(
            $request->user(),
            $user,
            $request->string('reason')->toString(),
        );

        return ApiResponse::success(
            (new AdminUserResource($updated, true))->resolve($request),
        );
    }

    public function restore(AdminUserStatusActionRequest $request, int $user): JsonResponse
    {
        $updated = $this->users->restore(
            $request->user(),
            $user,
            $request->string('reason')->toString(),
        );

        return ApiResponse::success(
            (new AdminUserResource($updated, true))->resolve($request),
        );
    }

    public function ban(AdminUserStatusActionRequest $request, int $user): JsonResponse
    {
        $updated = $this->users->ban(
            $request->user(),
            $user,
            $request->string('reason')->toString(),
        );

        return ApiResponse::success(
            (new AdminUserResource($updated, true))->resolve($request),
        );
    }

    private function perPage(AdminUserIndexRequest $request): int
    {
        return min(
            max(1, (int) $request->input('per_page', config('api.pagination.default_per_page'))),
            (int) config('api.pagination.max_per_page'),
        );
    }
}
