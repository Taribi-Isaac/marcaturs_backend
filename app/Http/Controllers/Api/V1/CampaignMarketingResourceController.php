<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Campaigns\StoreCampaignMarketingResourceRequest;
use App\Http\Requests\Api\V1\Campaigns\UpdateCampaignMarketingResourceRequest;
use App\Http\Resources\Api\V1\CampaignMarketingResourceResource;
use App\Models\Campaign;
use App\Models\CampaignMarketingResource;
use App\Services\Campaigns\CampaignMarketingResourceService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CampaignMarketingResourceController extends Controller
{
    public function __construct(
        private readonly CampaignMarketingResourceService $resources,
    ) {}

    public function index(Request $request, Campaign $campaign): JsonResponse
    {
        return ApiResponse::success(
            CampaignMarketingResourceResource::collection(
                $this->resources->listForOwner($request->user(), $campaign),
            )->resolve($request),
        );
    }

    public function store(StoreCampaignMarketingResourceRequest $request, Campaign $campaign): JsonResponse
    {
        $resource = $this->resources->create(
            $request->user(),
            $campaign,
            $request->safe()->except('file'),
            $request->file('file'),
        );

        return ApiResponse::success(
            (new CampaignMarketingResourceResource($resource))->resolve($request),
            201,
        );
    }

    public function show(Request $request, Campaign $campaign, CampaignMarketingResource $marketingResource): JsonResponse
    {
        $found = $this->resources->showForOwner($request->user(), $campaign, $marketingResource);

        return ApiResponse::success(
            (new CampaignMarketingResourceResource($found))->resolve($request),
        );
    }

    public function update(
        UpdateCampaignMarketingResourceRequest $request,
        Campaign $campaign,
        CampaignMarketingResource $marketingResource,
    ): JsonResponse {
        $updated = $this->resources->update(
            $request->user(),
            $campaign,
            $marketingResource,
            $request->safe()->except('file'),
            $request->file('file'),
        );

        return ApiResponse::success(
            (new CampaignMarketingResourceResource($updated))->resolve($request),
        );
    }

    public function destroy(Request $request, Campaign $campaign, CampaignMarketingResource $marketingResource): JsonResponse
    {
        $this->resources->delete($request->user(), $campaign, $marketingResource);

        return ApiResponse::success(null);
    }

    public function download(Request $request, Campaign $campaign, CampaignMarketingResource $marketingResource): StreamedResponse
    {
        return $this->resources->downloadForOwner($request->user(), $campaign, $marketingResource);
    }
}
