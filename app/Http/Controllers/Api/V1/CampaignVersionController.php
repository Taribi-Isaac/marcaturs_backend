<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Campaigns\StoreCampaignVersionRequest;
use App\Http\Requests\Api\V1\Campaigns\UpdateCampaignVersionRequest;
use App\Http\Resources\Api\V1\CampaignVersionResource;
use App\Models\Campaign;
use App\Models\CampaignVersion;
use App\Services\Campaigns\CampaignVersionService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CampaignVersionController extends Controller
{
    public function __construct(
        private readonly CampaignVersionService $versions,
    ) {}

    public function index(Request $request, Campaign $campaign): JsonResponse
    {
        return ApiResponse::success(
            CampaignVersionResource::collection(
                $this->versions->index($request->user(), $campaign),
            )->resolve($request),
        );
    }

    public function store(StoreCampaignVersionRequest $request, Campaign $campaign): JsonResponse
    {
        $version = $this->versions->create($request->user(), $campaign, $request->validated());

        return ApiResponse::success(
            (new CampaignVersionResource($version))->resolve($request),
            201,
        );
    }

    public function show(Request $request, Campaign $campaign, CampaignVersion $version): JsonResponse
    {
        $owned = $this->versions->show($request->user(), $campaign, $version);

        return ApiResponse::success(
            (new CampaignVersionResource($owned))->resolve($request),
        );
    }

    public function update(UpdateCampaignVersionRequest $request, Campaign $campaign, CampaignVersion $version): JsonResponse
    {
        $updated = $this->versions->update($request->user(), $campaign, $version, $request->validated());

        return ApiResponse::success(
            (new CampaignVersionResource($updated))->resolve($request),
        );
    }

    public function publish(Request $request, Campaign $campaign, CampaignVersion $version): JsonResponse
    {
        $published = $this->versions->publish($request->user(), $campaign, $version);

        return ApiResponse::success(
            (new CampaignVersionResource($published))->resolve($request),
        );
    }
}
