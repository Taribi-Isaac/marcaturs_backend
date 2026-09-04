<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Deals\CancelDealRequest;
use App\Http\Resources\Api\V1\DealResource;
use App\Models\Deal;
use App\Services\Deals\DealCancellationService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class DealCancellationController extends Controller
{
    public function __construct(
        private readonly DealCancellationService $cancellations,
    ) {}

    public function cancel(CancelDealRequest $request, Deal $deal): JsonResponse
    {
        $cancelled = $this->cancellations->cancel(
            $request->user(),
            $deal,
            $request->validated(),
        );

        return ApiResponse::success(
            (new DealResource($cancelled))->resolve($request),
        );
    }
}
