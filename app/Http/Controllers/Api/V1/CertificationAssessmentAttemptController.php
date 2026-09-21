<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Certification\SubmitCertificationAssessmentAttemptRequest;
use App\Http\Resources\Api\V1\CertificationAssessmentAttemptResource;
use App\Models\CertificationAssessmentAttempt;
use App\Models\CertificationEnrollment;
use App\Services\Certification\CertificationAssessmentAttemptService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CertificationAssessmentAttemptController extends Controller
{
    public function __construct(
        private readonly CertificationAssessmentAttemptService $attempts,
    ) {}

    public function index(Request $request, CertificationEnrollment $enrollment): JsonResponse
    {
        $items = $this->attempts->indexForEnrollment($request->user(), $enrollment);

        return ApiResponse::success(
            $items->map(
                fn (CertificationAssessmentAttempt $attempt) => (new CertificationAssessmentAttemptResource($attempt))->resolve($request),
            )->values()->all(),
        );
    }

    public function store(Request $request, CertificationEnrollment $enrollment): JsonResponse
    {
        $result = $this->attempts->start($request->user(), $enrollment);

        return ApiResponse::success(
            (new CertificationAssessmentAttemptResource($result['attempt']))->resolve($request),
            $result['created'] ? 201 : 200,
        );
    }

    public function show(
        Request $request,
        CertificationEnrollment $enrollment,
        CertificationAssessmentAttempt $attempt,
    ): JsonResponse {
        $attempt = $this->attempts->showForEnrollment($request->user(), $enrollment, $attempt);

        return ApiResponse::success(
            (new CertificationAssessmentAttemptResource($attempt))->resolve($request),
        );
    }

    public function submit(
        SubmitCertificationAssessmentAttemptRequest $request,
        CertificationEnrollment $enrollment,
        CertificationAssessmentAttempt $attempt,
    ): JsonResponse {
        $submitted = $this->attempts->submit(
            $request->user(),
            $enrollment,
            $attempt,
            $request->validated('answers'),
        );

        return ApiResponse::success(
            (new CertificationAssessmentAttemptResource($submitted))->resolve($request),
        );
    }
}
