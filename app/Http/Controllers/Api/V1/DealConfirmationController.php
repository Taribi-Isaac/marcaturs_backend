<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Deals\ConfirmDealRequest;
use App\Http\Requests\Api\V1\Deals\RejectPaymentEvidenceRequest;
use App\Http\Resources\Api\V1\DealResource;
use App\Http\Resources\Api\V1\PaymentEvidenceResource;
use App\Models\Deal;
use App\Models\PaymentEvidence;
use App\Services\Deals\DealConfirmationService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class DealConfirmationController extends Controller
{
    public function __construct(
        private readonly DealConfirmationService $confirmations,
    ) {}

    public function confirm(ConfirmDealRequest $request, Deal $deal): JsonResponse
    {
        $confirmed = $this->confirmations->confirm(
            $request->user(),
            $deal,
            $request->validated(),
        );

        return ApiResponse::success(
            (new DealResource($confirmed))->resolve($request),
        );
    }

    public function reject(
        RejectPaymentEvidenceRequest $request,
        Deal $deal,
        PaymentEvidence $paymentEvidence,
    ): JsonResponse {
        $rejected = $this->confirmations->reject(
            $request->user(),
            $deal,
            $paymentEvidence,
            $request->validated(),
        );

        return ApiResponse::success(
            (new PaymentEvidenceResource($rejected))->resolve($request),
        );
    }
}
