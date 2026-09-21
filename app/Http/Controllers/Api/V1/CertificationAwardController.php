<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CertificationAwardResource;
use App\Models\CertificationAward;
use App\Services\Certification\CertificationAwardService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CertificationAwardController extends Controller
{
    public function __construct(
        private readonly CertificationAwardService $awards,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->awards->indexForAmbassador($request->user())
                ->map(fn (CertificationAward $award) => (new CertificationAwardResource($award))->resolve($request))
                ->values()
                ->all(),
        );
    }

    public function show(Request $request, CertificationAward $award): JsonResponse
    {
        $award = $this->awards->showForAmbassador($request->user(), $award);

        return ApiResponse::success(
            (new CertificationAwardResource($award))->resolve($request),
        );
    }
}
