<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CertificationAssessmentAttemptResource;
use App\Models\CertificationAssessmentAttempt;
use App\Models\CertificationEnrollment;
use App\Services\Certification\CertificationAssessmentAttemptService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCertificationAssessmentAttemptController extends Controller
{
    public function __construct(
        private readonly CertificationAssessmentAttemptService $attempts,
    ) {}

    public function index(Request $request, CertificationEnrollment $enrollment): JsonResponse
    {
        $items = $this->attempts->adminIndex($request->user(), $enrollment);

        return ApiResponse::success(
            $items->map(
                fn (CertificationAssessmentAttempt $attempt) => (new CertificationAssessmentAttemptResource($attempt, admin: true))->resolve($request),
            )->values()->all(),
        );
    }

    public function show(
        Request $request,
        CertificationEnrollment $enrollment,
        CertificationAssessmentAttempt $attempt,
    ): JsonResponse {
        $attempt = $this->attempts->adminShow($request->user(), $enrollment, $attempt);

        return ApiResponse::success(
            (new CertificationAssessmentAttemptResource($attempt, admin: true))->resolve($request),
        );
    }
}
