<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Marketplace\CampaignBookmarkIndexRequest;
use App\Http\Resources\Api\V1\CampaignBookmarkResource;
use App\Services\Campaigns\CampaignBookmarkService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CampaignBookmarkController extends Controller
{
    public function __construct(
        private readonly CampaignBookmarkService $bookmarks,
    ) {}

    public function index(CampaignBookmarkIndexRequest $request): JsonResponse
    {
        $paginator = $this->bookmarks->list($request->user(), $request);

        return ApiResponse::paginated(
            $paginator,
            CampaignBookmarkResource::collection($paginator->getCollection())->resolve($request),
        );
    }

    public function update(Request $request, int $campaign): JsonResponse
    {
        $bookmark = $this->bookmarks->save($request->user(), $campaign);

        return ApiResponse::success([
            'campaign_id' => $bookmark->campaign_id,
            'bookmarked_at' => $bookmark->created_at?->toIso8601String(),
        ]);
    }

    public function destroy(Request $request, int $campaign): JsonResponse
    {
        $this->bookmarks->remove($request->user(), $campaign);

        return response()->json(null, 204);
    }
}
