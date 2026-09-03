<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Commissions\ConfirmCommissionReceivedRequest;
use App\Http\Requests\Api\V1\Commissions\MarkCommissionPaidRequest;
use App\Http\Resources\Api\V1\CommissionResource;
use App\Models\Commission;
use App\Services\Deals\CommissionQueryService;
use App\Services\Deals\CommissionSettlementService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommissionController extends Controller
{
    public function __construct(
        private readonly CommissionQueryService $commissions,
        private readonly CommissionSettlementService $settlements,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->commissions->listForParticipant(
            $request->user(),
            $this->perPage($request),
        );

        return ApiResponse::paginated(
            $paginator,
            CommissionResource::collection($paginator->getCollection())->resolve($request),
        );
    }

    public function show(Request $request, Commission $commission): JsonResponse
    {
        $found = $this->commissions->showForParticipant($request->user(), $commission);

        return ApiResponse::success(
            (new CommissionResource($found))->resolve($request),
        );
    }

    public function markPaid(MarkCommissionPaidRequest $request, Commission $commission): JsonResponse
    {
        $updated = $this->settlements->markPaid(
            $request->user(),
            $commission,
            $request->validated(),
        );

        return ApiResponse::success(
            (new CommissionResource($updated))->resolve($request),
        );
    }

    public function confirmReceived(ConfirmCommissionReceivedRequest $request, Commission $commission): JsonResponse
    {
        $updated = $this->settlements->confirmReceived(
            $request->user(),
            $commission,
            $request->validated(),
        );

        return ApiResponse::success(
            (new CommissionResource($updated))->resolve($request),
        );
    }

    private function perPage(Request $request): int
    {
        return min(
            max(1, (int) $request->input('per_page', config('api.pagination.default_per_page'))),
            (int) config('api.pagination.max_per_page'),
        );
    }
}
