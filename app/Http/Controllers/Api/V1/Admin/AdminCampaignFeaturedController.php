<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Campaigns\StoreCampaignFeaturedPackageRequest;
use App\Http\Requests\Api\V1\Admin\Campaigns\UpdateCampaignFeaturedPackageRequest;
use App\Http\Resources\Api\V1\CampaignFeaturedPackageResource;
use App\Http\Resources\Api\V1\CampaignFeaturedPurchaseResource;
use App\Models\Campaign;
use App\Models\CampaignFeaturedPackage;
use App\Services\Campaigns\CampaignFeaturedPackageAdminService;
use App\Services\Campaigns\CampaignFeaturedService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCampaignFeaturedController extends Controller
{
    public function __construct(
        private readonly CampaignFeaturedPackageAdminService $packages,
        private readonly CampaignFeaturedService $featured,
    ) {}

    public function indexPackages(Request $request): JsonResponse
    {
        return ApiResponse::success(
            CampaignFeaturedPackageResource::collection(
                $this->packages->all($request->user()),
            )->resolve($request),
        );
    }

    public function storePackage(StoreCampaignFeaturedPackageRequest $request): JsonResponse
    {
        $package = $this->packages->create($request->user(), $request->validated());

        return ApiResponse::success(
            (new CampaignFeaturedPackageResource($package))->resolve($request),
            201,
        );
    }

    public function updatePackage(UpdateCampaignFeaturedPackageRequest $request, CampaignFeaturedPackage $package): JsonResponse
    {
        $updated = $this->packages->update($request->user(), $package, $request->validated());

        return ApiResponse::success(
            (new CampaignFeaturedPackageResource($updated))->resolve($request),
        );
    }

    public function indexPurchases(Request $request, Campaign $campaign): JsonResponse
    {
        return ApiResponse::success(
            CampaignFeaturedPurchaseResource::collection(
                $this->featured->adminHistory($request->user(), $campaign),
            )->resolve($request),
        );
    }
}
