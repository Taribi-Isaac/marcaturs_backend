<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Campaigns\CloseCampaignRequest;
use App\Http\Requests\Api\V1\Admin\Campaigns\ReviewCampaignRequest;
use App\Http\Resources\Api\V1\AdminCampaignResource;
use App\Models\Campaign;
use App\Services\Campaigns\CampaignLifecycleService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCampaignController extends Controller
{
    public function __construct(
        private readonly CampaignLifecycleService $lifecycle,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Campaign::query()
            ->with(['category', 'currentVersion', 'user'])
            ->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        $paginator = $query->paginate();

        return ApiResponse::paginated(
            $paginator,
            AdminCampaignResource::collection($paginator->getCollection())->resolve($request),
        );
    }

    public function show(Campaign $campaign): JsonResponse
    {
        return ApiResponse::success(
            (new AdminCampaignResource($this->lifecycle->adminShow($campaign), true))->resolve(),
        );
    }

    public function approve(Request $request, Campaign $campaign): JsonResponse
    {
        $updated = $this->lifecycle->approve($request->user(), $campaign);

        return ApiResponse::success(
            (new AdminCampaignResource($updated->load('user')))->resolve($request),
        );
    }

    public function reject(ReviewCampaignRequest $request, Campaign $campaign): JsonResponse
    {
        $updated = $this->lifecycle->reject(
            $request->user(),
            $campaign,
            $request->string('reason')->toString(),
        );

        return ApiResponse::success(
            (new AdminCampaignResource($updated->load('user')))->resolve($request),
        );
    }

    public function requestModification(ReviewCampaignRequest $request, Campaign $campaign): JsonResponse
    {
        $updated = $this->lifecycle->requestModification(
            $request->user(),
            $campaign,
            $request->string('reason')->toString(),
        );

        return ApiResponse::success(
            (new AdminCampaignResource($updated->load('user')))->resolve($request),
        );
    }

    public function activate(Request $request, Campaign $campaign): JsonResponse
    {
        $updated = $this->lifecycle->activate($request->user(), $campaign);

        return ApiResponse::success(
            (new AdminCampaignResource($updated->load('user')))->resolve($request),
        );
    }

    public function suspend(ReviewCampaignRequest $request, Campaign $campaign): JsonResponse
    {
        $updated = $this->lifecycle->suspend(
            $request->user(),
            $campaign,
            $request->string('reason')->toString(),
        );

        return ApiResponse::success(
            (new AdminCampaignResource($updated->load('user')))->resolve($request),
        );
    }

    public function close(CloseCampaignRequest $request, Campaign $campaign): JsonResponse
    {
        $updated = $this->lifecycle->close(
            $request->user(),
            $campaign,
            $request->input('reason'),
        );

        return ApiResponse::success(
            (new AdminCampaignResource($updated->load('user')))->resolve($request),
        );
    }
}
