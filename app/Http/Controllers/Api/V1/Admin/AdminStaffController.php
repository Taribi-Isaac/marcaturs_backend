<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AccountStatus;
use App\Enums\AdminStaffRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Staff\AcceptStaffInvitationRequest;
use App\Http\Requests\Api\V1\Admin\Staff\AdminStaffIndexRequest;
use App\Http\Requests\Api\V1\Admin\Staff\ChangeStaffRoleRequest;
use App\Http\Requests\Api\V1\Admin\Staff\CreateStaffDirectRequest;
use App\Http\Requests\Api\V1\Admin\Staff\InviteStaffRequest;
use App\Http\Requests\Api\V1\Admin\Staff\StaffStatusActionRequest;
use App\Http\Resources\Api\V1\Admin\AdminStaffInvitationResource;
use App\Http\Resources\Api\V1\Admin\AdminStaffResource;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\Admin\AdminStaffService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminStaffController extends Controller
{
    public function __construct(
        private readonly AdminStaffService $staff,
    ) {}

    public function index(AdminStaffIndexRequest $request): JsonResponse
    {
        $role = $request->filled('staff_role')
            ? AdminStaffRole::from($request->string('staff_role')->toString())
            : null;

        $status = $request->filled('status')
            ? AccountStatus::from($request->string('status')->toString())
            : null;

        $paginator = $this->staff->list(
            $request->user(),
            $role,
            $status,
            $request->input('q'),
            $this->perPage($request),
        );

        return ApiResponse::paginated(
            $paginator,
            collect($paginator->items())->map(
                fn ($user) => (new AdminStaffResource($user))->resolve($request),
            )->all(),
        );
    }

    public function show(Request $request, int $user): JsonResponse
    {
        return ApiResponse::success(
            (new AdminStaffResource($this->staff->show($request->user(), $user), true))->resolve($request),
        );
    }

    public function invite(InviteStaffRequest $request): JsonResponse
    {
        $result = $this->staff->invite(
            $request->user(),
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            AdminStaffRole::from($request->string('staff_role')->toString()),
        );

        $this->staff->sendInvitationNotification($result['invitation'], $result['plain_token']);

        $payload = (new AdminStaffInvitationResource($result['invitation']))->resolve($request);

        if (! app()->environment('production')) {
            $payload['debug_token'] = $result['plain_token'];
        }

        return ApiResponse::success($payload, 201);
    }

    public function revokeInvitation(Request $request, int $invitation): JsonResponse
    {
        return ApiResponse::success(
            (new AdminStaffInvitationResource(
                $this->staff->revokeInvitation($request->user(), $invitation),
            ))->resolve($request),
        );
    }

    public function changeRole(ChangeStaffRoleRequest $request, int $user): JsonResponse
    {
        return ApiResponse::success(
            (new AdminStaffResource(
                $this->staff->changeRole(
                    $request->user(),
                    $user,
                    AdminStaffRole::from($request->string('staff_role')->toString()),
                ),
            ))->resolve($request),
        );
    }

    public function disable(StaffStatusActionRequest $request, int $user): JsonResponse
    {
        return ApiResponse::success(
            (new AdminStaffResource(
                $this->staff->disable($request->user(), $user, $request->string('reason')->toString()),
            ))->resolve($request),
        );
    }

    public function restore(StaffStatusActionRequest $request, int $user): JsonResponse
    {
        return ApiResponse::success(
            (new AdminStaffResource(
                $this->staff->restore($request->user(), $user, $request->string('reason')->toString()),
            ))->resolve($request),
        );
    }

    public function createDirect(CreateStaffDirectRequest $request): JsonResponse
    {
        $user = $this->staff->createDirect(
            $request->user(),
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            AdminStaffRole::from($request->string('staff_role')->toString()),
        );

        return ApiResponse::success(
            (new AdminStaffResource($user))->resolve($request),
            201,
        );
    }

    public function acceptInvitation(AcceptStaffInvitationRequest $request): JsonResponse
    {
        $user = $this->staff->acceptInvitation(
            $request->string('token')->toString(),
            $request->string('password')->toString(),
        );

        return ApiResponse::success(
            (new UserResource($user))->resolve($request),
            201,
        );
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->input('per_page', 15);

        return max(1, min(100, $perPage));
    }
}
