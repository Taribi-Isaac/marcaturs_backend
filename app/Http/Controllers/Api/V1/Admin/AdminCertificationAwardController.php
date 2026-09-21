<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CertificationAwardResource;
use App\Models\CertificationAward;
use App\Models\CertificationEnrollment;
use App\Services\Certification\CertificationAwardService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCertificationAwardController extends Controller
{
    public function __construct(
        private readonly CertificationAwardService $awards,
    ) {}

    public function indexForEnrollment(Request $request, CertificationEnrollment $enrollment): JsonResponse
    {
        return ApiResponse::success(
            $this->awards->adminIndexForEnrollment($request->user(), $enrollment)
                ->map(fn (CertificationAward $award) => (new CertificationAwardResource($award, admin: true))->resolve($request))
                ->values()
                ->all(),
        );
    }

    public function show(Request $request, CertificationAward $award): JsonResponse
    {
        $award = $this->awards->adminShow($request->user(), $award);

        return ApiResponse::success(
            (new CertificationAwardResource($award, admin: true))->resolve($request),
        );
    }
}
