<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OverallVerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Marketplace\MarketplaceCampaignIndexRequest;
use App\Http\Resources\Api\V1\MarketplaceCampaignCardResource;
use App\Http\Resources\Api\V1\MarketplaceCampaignDetailResource;
use App\Models\Campaign;
use App\Services\Campaigns\CampaignCoverService;
use App\Services\Campaigns\CampaignDiscoveryService;
use App\Services\Campaigns\CampaignMarketingResourceService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MarketplaceCampaignController extends Controller
{
    public function __construct(
        private readonly CampaignDiscoveryService $discovery,
        private readonly CampaignMarketingResourceService $resources,
        private readonly CampaignCoverService $covers,
    ) {}

    public function index(MarketplaceCampaignIndexRequest $request): JsonResponse
    {
        $paginator = $this->discovery->index($request);
        $this->attachVerification($paginator->getCollection());

        return ApiResponse::paginated(
            $paginator,
            MarketplaceCampaignCardResource::collection($paginator->getCollection())->resolve($request),
        );
    }

    public function show(int $campaign): JsonResponse
    {
        $found = $this->discovery->show($campaign);
        $this->attachVerification(collect([$found]));

        return ApiResponse::success(
            (new MarketplaceCampaignDetailResource($found))->resolve(),
        );
    }

    public function downloadResource(Request $request, int $campaign, int $resource): StreamedResponse
    {
        return $this->resources->downloadForAmbassador($request->user(), $campaign, $resource);
    }

    public function cover(int $campaign): StreamedResponse
    {
        return $this->covers->streamPublic($campaign);
    }

    /**
     * @param  iterable<int, Campaign>  $campaigns
     */
    private function attachVerification(iterable $campaigns): void
    {
        $statuses = $this->discovery->verificationStatuses($campaigns);

        foreach ($campaigns as $campaign) {
            $campaign->setAttribute(
                'marketplace_verification_status',
                $statuses[$campaign->user_id] ?? OverallVerificationStatus::NotStarted,
            );
        }
    }
}
