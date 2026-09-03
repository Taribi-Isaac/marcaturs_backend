<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Campaigns\StoreCampaignExtensionPackageRequest;
use App\Http\Requests\Api\V1\Admin\Campaigns\UpdateCampaignExtensionPackageRequest;
use App\Http\Resources\Api\V1\CampaignExtensionPackageResource;
use App\Http\Resources\Api\V1\CampaignExtensionResource;
use App\Models\Campaign;
use App\Models\CampaignExtensionPackage;
use App\Services\Campaigns\CampaignExtensionPackageAdminService;
use App\Services\Campaigns\CampaignExtensionService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCampaignExtensionController extends Controller
{
    public function __construct(
        private readonly CampaignExtensionPackageAdminService $packages,
        private readonly CampaignExtensionService $extensions,
    ) {}

    public function indexPackages(Request $request): JsonResponse
    {
        return ApiResponse::success(
            CampaignExtensionPackageResource::collection(
                $this->packages->all($request->user()),
            )->resolve($request),
        );
    }

    public function storePackage(StoreCampaignExtensionPackageRequest $request): JsonResponse
    {
        $package = $this->packages->create($request->user(), $request->validated());

        return ApiResponse::success(
            (new CampaignExtensionPackageResource($package))->resolve($request),
            201,
        );
    }

    public function updatePackage(UpdateCampaignExtensionPackageRequest $request, CampaignExtensionPackage $package): JsonResponse
    {
        $updated = $this->packages->update($request->user(), $package, $request->validated());

        return ApiResponse::success(
            (new CampaignExtensionPackageResource($updated))->resolve($request),
        );
    }

    public function indexExtensions(Request $request, Campaign $campaign): JsonResponse
    {
        return ApiResponse::success(
            CampaignExtensionResource::collection(
                $this->extensions->adminHistory($request->user(), $campaign),
            )->resolve($request),
        );
    }
}
