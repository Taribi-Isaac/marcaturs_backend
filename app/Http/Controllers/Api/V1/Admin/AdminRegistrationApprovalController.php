<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\RegistrationApprovalStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\RegistrationApprovals\RegistrationApprovalIndexRequest;
use App\Http\Requests\Api\V1\Admin\RegistrationApprovals\RevokeRegistrationApprovalRequest;
use App\Http\Requests\Api\V1\Admin\RegistrationApprovals\StoreRegistrationApprovalRequest;
use App\Http\Resources\Api\V1\Admin\RegistrationApprovalResource;
use App\Models\RegistrationApproval;
use App\Services\Auth\RegistrationApprovalService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminRegistrationApprovalController extends Controller
{
    public function __construct(
        private readonly RegistrationApprovalService $approvals,
    ) {}

    public function index(RegistrationApprovalIndexRequest $request): JsonResponse
    {
        $status = $request->filled('status')
            ? RegistrationApprovalStatus::from($request->string('status')->toString())
            : null;

        $role = $request->filled('role')
            ? Role::from($request->string('role')->toString())
            : null;

        $paginator = $this->approvals->list(
            $request->user(),
            $status,
            $role,
            $request->input('q'),
            $this->perPage($request),
        );

        return ApiResponse::paginated(
            $paginator,
            collect($paginator->items())->map(
                fn (RegistrationApproval $approval) => (new RegistrationApprovalResource($approval))->resolve($request),
            )->all(),
        );
    }

    public function show(Request $request, int $approval): JsonResponse
    {
        return ApiResponse::success(
            (new RegistrationApprovalResource($this->approvals->show($request->user(), $approval), true))->resolve($request),
        );
    }

    public function store(StoreRegistrationApprovalRequest $request): JsonResponse
    {
        $approval = $this->approvals->create(
            $request->user(),
            $request->string('email')->toString(),
            Role::from($request->string('role')->toString()),
            $request->filled('note') ? $request->string('note')->toString() : null,
        );

        return ApiResponse::success(
            (new RegistrationApprovalResource($approval))->resolve($request),
            201,
        );
    }

    public function revoke(RevokeRegistrationApprovalRequest $request, int $approval): JsonResponse
    {
        return ApiResponse::success(
            (new RegistrationApprovalResource(
                $this->approvals->revoke(
                    $request->user(),
                    $approval,
                    $request->filled('reason') ? $request->string('reason')->toString() : null,
                ),
            ))->resolve($request),
        );
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->input('per_page', 15);

        return max(1, min(100, $perPage));
    }
}
