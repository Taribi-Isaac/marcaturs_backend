<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OfficialPaymentInformationResource;
use App\Services\Campaigns\OfficialPaymentInformationService;
use App\Services\Verification\VerificationStatusCalculator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class OfficialPaymentInformationController extends Controller
{
    public function __construct(
        private readonly OfficialPaymentInformationService $officialPayment,
        private readonly VerificationStatusCalculator $verification,
    ) {}

    public function show(string $token): JsonResponse
    {
        $campaign = $this->officialPayment->resolveByToken($token);

        if ($campaign->user !== null) {
            $campaign->setAttribute(
                'marketplace_verification_status',
                $this->verification->overall($campaign->user),
            );
        }

        return ApiResponse::success(
            (new OfficialPaymentInformationResource($campaign))->resolve(),
        );
    }
}
