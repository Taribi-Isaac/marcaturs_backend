<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Deals\StorePaymentEvidenceRequest;
use App\Http\Resources\Api\V1\PaymentEvidenceResource;
use App\Models\Deal;
use App\Models\PaymentEvidence;
use App\Services\Deals\PaymentEvidenceService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentEvidenceController extends Controller
{
    public function __construct(
        private readonly PaymentEvidenceService $evidence,
    ) {}

    public function index(Request $request, Deal $deal): JsonResponse
    {
        $items = $this->evidence->listForParticipant($request->user(), $deal);

        return ApiResponse::success(
            PaymentEvidenceResource::collection($items)->resolve($request),
        );
    }

    public function store(StorePaymentEvidenceRequest $request, Deal $deal): JsonResponse
    {
        $created = $this->evidence->submit(
            $request->user(),
            $deal,
            $request->safe()->except('file'),
            $request->file('file'),
        );

        return ApiResponse::success(
            (new PaymentEvidenceResource($created))->resolve($request),
            201,
        );
    }

    public function show(Request $request, Deal $deal, PaymentEvidence $paymentEvidence): JsonResponse
    {
        $found = $this->evidence->showForParticipant($request->user(), $deal, $paymentEvidence);

        return ApiResponse::success(
            (new PaymentEvidenceResource($found))->resolve($request),
        );
    }

    public function download(Request $request, Deal $deal, PaymentEvidence $paymentEvidence): StreamedResponse
    {
        return $this->evidence->streamForParticipant($request->user(), $deal, $paymentEvidence);
    }
}
