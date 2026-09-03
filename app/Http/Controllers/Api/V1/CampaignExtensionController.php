<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Campaigns\InitializeCampaignExtensionRequest;
use App\Http\Requests\Api\V1\Campaigns\VerifyCampaignExtensionRequest;
use App\Http\Resources\Api\V1\CampaignExtensionPackageResource;
use App\Http\Resources\Api\V1\CampaignExtensionResource;
use App\Http\Resources\Api\V1\PlatformPaymentResource;
use App\Models\Campaign;
use App\Services\Campaigns\CampaignExtensionService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CampaignExtensionController extends Controller
{
    public function __construct(
        private readonly CampaignExtensionService $extensions,
    ) {}

    public function packages(Request $request, Campaign $campaign): JsonResponse
    {
        return ApiResponse::success(
            CampaignExtensionPackageResource::collection(
                $this->extensions->listPackages($request->user(), $campaign),
            )->resolve($request),
        );
    }

    public function index(Request $request, Campaign $campaign): JsonResponse
    {
        return ApiResponse::success(
            CampaignExtensionResource::collection(
                $this->extensions->history($request->user(), $campaign),
            )->resolve($request),
        );
    }

    public function initialize(InitializeCampaignExtensionRequest $request, Campaign $campaign): JsonResponse
    {
        $result = $this->extensions->initialize(
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

    public function verify(VerifyCampaignExtensionRequest $request, Campaign $campaign): JsonResponse
    {
        $extension = $this->extensions->confirmOwnedReference(
            $request->user(),
            $campaign,
            (string) $request->validated('reference'),
        );

        return ApiResponse::success(
            (new CampaignExtensionResource($extension))->resolve($request),
        );
    }
}
