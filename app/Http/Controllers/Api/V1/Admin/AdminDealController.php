<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CommissionStatus;
use App\Enums\DealStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Deals\AdminDealIndexRequest;
use App\Http\Resources\Api\V1\AdminDealResource;
use App\Services\Admin\AdminDealService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminDealController extends Controller
{
    public function __construct(
        private readonly AdminDealService $deals,
    ) {}

    public function index(AdminDealIndexRequest $request): JsonResponse
    {
        $statusValue = $request->validated('status');
        $status = is_string($statusValue) ? DealStatus::from($statusValue) : $statusValue;

        $commissionStatusValue = $request->validated('commission_status');
        $commissionStatus = is_string($commissionStatusValue)
            ? CommissionStatus::from($commissionStatusValue)
            : $commissionStatusValue;

        $paginator = $this->deals->list(
            $status instanceof DealStatus ? $status : null,
            $request->input('q'),
            $request->has('open_dispute') ? $request->boolean('open_dispute') : null,
            $request->has('commission_overdue') ? $request->boolean('commission_overdue') : null,
            $commissionStatus instanceof CommissionStatus ? $commissionStatus : null,
            $this->perPage($request),
        );

        return ApiResponse::paginated(
            $paginator,
            collect($paginator->items())->map(
                fn ($deal) => (new AdminDealResource($deal))->resolve($request),
            )->all(),
        );
    }

    public function show(Request $request, int $deal): JsonResponse
    {
        return ApiResponse::success(
            (new AdminDealResource($this->deals->show($deal), true))->resolve($request),
        );
    }

    public function downloadEvidence(Request $request, int $deal, int $paymentEvidence): StreamedResponse
    {
        return $this->deals->streamEvidence(
            $deal,
            $paymentEvidence,
            (int) $request->user()->id,
        );
    }

    private function perPage(AdminDealIndexRequest $request): int
    {
        return min(
            max(1, (int) $request->input('per_page', config('api.pagination.default_per_page'))),
            (int) config('api.pagination.max_per_page'),
        );
    }
}
