<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CampaignCoverResource;
use App\Models\Campaign;
use App\Services\Campaigns\CampaignCoverService;
use App\Support\Api\ApiResponse;
use App\Support\Campaigns\CampaignCoverPresentation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminCampaignCoverController extends Controller
{
    public function __construct(
        private readonly CampaignCoverService $covers,
    ) {}

    public function show(Request $request, Campaign $campaign): JsonResponse
    {
        $cover = $this->covers->showForAdmin($request->user(), $campaign);

        return ApiResponse::success([
            ...(new CampaignCoverResource($cover))->resolve($request),
            'url' => CampaignCoverPresentation::adminDownloadUrl($campaign->id),
        ]);
    }

    public function download(Request $request, Campaign $campaign): StreamedResponse
    {
        return $this->covers->downloadForAdmin($request->user(), $campaign);
    }
}
