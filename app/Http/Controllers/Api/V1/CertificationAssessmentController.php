<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CertificationLearnerAssessmentResource;
use App\Models\CertificationEnrollment;
use App\Services\Certification\CertificationAssessmentLearnerService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CertificationAssessmentController extends Controller
{
    public function __construct(
        private readonly CertificationAssessmentLearnerService $assessments,
    ) {}

    public function show(Request $request, CertificationEnrollment $enrollment): JsonResponse
    {
        $payload = $this->assessments->showForEnrollment($request->user(), $enrollment);

        return ApiResponse::success(
            (new CertificationLearnerAssessmentResource($payload))->resolve($request),
        );
    }
}
