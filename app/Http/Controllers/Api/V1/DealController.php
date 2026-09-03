<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Deals\StoreDealRequest;
use App\Http\Resources\Api\V1\DealResource;
use App\Models\Deal;
use App\Services\Deals\DealService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DealController extends Controller
{
    public function __construct(
        private readonly DealService $deals,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->deals->listForParticipant(
            $request->user(),
            $this->perPage($request),
        );

        return ApiResponse::paginated(
            $paginator,
            DealResource::collection($paginator->getCollection())->resolve($request),
        );
    }

    public function store(StoreDealRequest $request): JsonResponse
    {
        $deal = $this->deals->create($request->user(), $request->validated());

        return ApiResponse::success(
            (new DealResource($deal))->resolve($request),
            201,
        );
    }

    public function show(Request $request, Deal $deal): JsonResponse
    {
        $found = $this->deals->showForParticipant($request->user(), $deal);

        return ApiResponse::success(
            (new DealResource($found))->resolve($request),
        );
    }

    private function perPage(Request $request): int
    {
        return min(
            max(1, (int) $request->input('per_page', config('api.pagination.default_per_page'))),
            (int) config('api.pagination.max_per_page'),
        );
    }
}
