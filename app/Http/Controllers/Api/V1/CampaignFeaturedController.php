<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Campaigns\InitializeCampaignFeaturedRequest;
use App\Http\Requests\Api\V1\Campaigns\VerifyCampaignFeaturedRequest;
use App\Http\Resources\Api\V1\CampaignFeaturedPackageResource;
use App\Http\Resources\Api\V1\CampaignFeaturedPurchaseResource;
use App\Http\Resources\Api\V1\PlatformPaymentResource;
use App\Models\Campaign;
use App\Services\Campaigns\CampaignFeaturedService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CampaignFeaturedController extends Controller
{
    public function __construct(
        private readonly CampaignFeaturedService $featured,
    ) {}

    public function packages(Request $request): JsonResponse
    {
        return ApiResponse::success(
            CampaignFeaturedPackageResource::collection(
                $this->featured->listActivePackages(),
            )->resolve($request),
        );
    }

    public function show(Request $request, Campaign $campaign): JsonResponse
    {
        $status = $this->featured->status($request->user(), $campaign);

        return ApiResponse::success([
            'is_featured' => $status['is_featured'],
            'expires_at' => $status['expires_at'],
            'purchases' => CampaignFeaturedPurchaseResource::collection($status['purchases'])->resolve($request),
        ]);
    }

    public function initialize(InitializeCampaignFeaturedRequest $request, Campaign $campaign): JsonResponse
    {
        $result = $this->featured->initialize(
            $request->user(),
            $campaign,
            (int) $request->validated('package_id'),
        );

        return ApiResponse::success([
            'authorization_url' => $result['authorization_url'],
            'access_code' => $result['access_code'],
            'payment' => (new PlatformPaymentResource($result['payment']))->resolve($request),
        ], 201);
    }

    public function verify(VerifyCampaignFeaturedRequest $request, Campaign $campaign): JsonResponse
    {
        $purchase = $this->featured->confirmOwnedReference(
            $request->user(),
            $campaign,
            (string) $request->validated('reference'),
        );

        return ApiResponse::success(
            (new CampaignFeaturedPurchaseResource($purchase))->resolve($request),
        );
    }
}
