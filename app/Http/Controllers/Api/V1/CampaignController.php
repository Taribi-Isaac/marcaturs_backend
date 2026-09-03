<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Campaigns\StoreCampaignRequest;
use App\Http\Requests\Api\V1\Campaigns\UpdateCampaignRequest;
use App\Http\Resources\Api\V1\CampaignResource;
use App\Models\Campaign;
use App\Services\Campaigns\CampaignLifecycleService;
use App\Services\Campaigns\CampaignService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CampaignController extends Controller
{
    public function __construct(
        private readonly CampaignService $campaigns,
        private readonly CampaignLifecycleService $lifecycle,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            CampaignResource::collection($this->campaigns->index($request->user()))->resolve($request),
        );
    }

    public function store(StoreCampaignRequest $request): JsonResponse
    {
        $campaign = $this->campaigns->create($request->user(), $request->validated());

        return ApiResponse::success(
            (new CampaignResource($campaign))->resolve($request),
            201,
        );
    }

    public function show(Request $request, Campaign $campaign): JsonResponse
    {
        $owned = $this->campaigns->show($request->user(), $campaign);

        return ApiResponse::success(
            (new CampaignResource($owned))->resolve($request),
        );
    }

    public function update(UpdateCampaignRequest $request, Campaign $campaign): JsonResponse
    {
        $updated = $this->campaigns->update($request->user(), $campaign, $request->validated());

        return ApiResponse::success(
            (new CampaignResource($updated))->resolve($request),
        );
    }

    public function submit(Request $request, Campaign $campaign): JsonResponse
    {
        $updated = $this->lifecycle->submit($request->user(), $campaign);

        return ApiResponse::success(
            (new CampaignResource($updated))->resolve($request),
        );
    }

    public function deactivate(Request $request, Campaign $campaign): JsonResponse
    {
        $updated = $this->lifecycle->deactivate($request->user(), $campaign);

        return ApiResponse::success(
            (new CampaignResource($updated))->resolve($request),
        );
    }
}
