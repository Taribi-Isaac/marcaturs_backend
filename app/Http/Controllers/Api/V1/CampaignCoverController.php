<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Campaigns\StoreCampaignCoverRequest;
use App\Http\Resources\Api\V1\CampaignCoverResource;
use App\Models\Campaign;
use App\Services\Campaigns\CampaignCoverService;
use App\Support\Api\ApiResponse;
use App\Support\Campaigns\CampaignCoverPresentation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CampaignCoverController extends Controller
{
    public function __construct(
        private readonly CampaignCoverService $covers,
    ) {}

    public function show(Request $request, Campaign $campaign): JsonResponse
    {
        $cover = $this->covers->showForOwner($request->user(), $campaign);

        return ApiResponse::success([
            ...(new CampaignCoverResource($cover))->resolve($request),
            'url' => CampaignCoverPresentation::ownerDownloadUrl($campaign->id),
        ]);
    }

    public function store(StoreCampaignCoverRequest $request, Campaign $campaign): JsonResponse
    {
        $existed = $campaign->cover()->exists();
        $cover = $this->covers->upsert(
            $request->user(),
            $campaign,
            $request->file('file'),
        );

        return ApiResponse::success([
            ...(new CampaignCoverResource($cover))->resolve($request),
            'url' => CampaignCoverPresentation::ownerDownloadUrl($campaign->id),
        ], $existed ? 200 : 201);
    }

    public function destroy(Request $request, Campaign $campaign): JsonResponse
    {
        $this->covers->delete($request->user(), $campaign);

        return ApiResponse::success(null);
    }

    public function download(Request $request, Campaign $campaign): StreamedResponse
    {
        return $this->covers->downloadForOwner($request->user(), $campaign);
    }
}
