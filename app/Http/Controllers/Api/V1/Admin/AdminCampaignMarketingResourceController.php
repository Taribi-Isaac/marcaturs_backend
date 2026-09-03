<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CampaignMarketingResourceResource;
use App\Models\Campaign;
use App\Models\CampaignMarketingResource;
use App\Services\Campaigns\CampaignMarketingResourceService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminCampaignMarketingResourceController extends Controller
{
    public function __construct(
        private readonly CampaignMarketingResourceService $resources,
    ) {}

    public function index(Request $request, Campaign $campaign): JsonResponse
    {
        return ApiResponse::success(
            CampaignMarketingResourceResource::collection(
                $this->resources->listForAdmin($request->user(), $campaign),
            )->resolve($request),
        );
    }

    public function download(
        Request $request,
        Campaign $campaign,
        CampaignMarketingResource $marketingResource,
    ): StreamedResponse {
        return $this->resources->downloadForAdmin($request->user(), $campaign, $marketingResource);
    }
}
