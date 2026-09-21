<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CertificationEnrollmentCurriculumResource;
use App\Http\Resources\Api\V1\CertificationLessonProgressResource;
use App\Models\CertificationEnrollment;
use App\Models\CertificationLesson;
use App\Models\CertificationResource;
use App\Services\Certification\CertificationLearningService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CertificationLearningController extends Controller
{
    public function __construct(
        private readonly CertificationLearningService $learning,
    ) {}

    public function curriculum(Request $request, CertificationEnrollment $enrollment): JsonResponse
    {
        $payload = $this->learning->curriculum($request->user(), $enrollment);

        return ApiResponse::success(
            (new CertificationEnrollmentCurriculumResource($payload))->resolve($request),
        );
    }

    public function progress(Request $request, CertificationEnrollment $enrollment): JsonResponse
    {
        return ApiResponse::success(
            $this->learning->progress($request->user(), $enrollment),
        );
    }

    public function completeLesson(
        Request $request,
        CertificationEnrollment $enrollment,
        CertificationLesson $lesson,
    ): JsonResponse {
        $progress = $this->learning->markLessonComplete($request->user(), $enrollment, $lesson);

        return ApiResponse::success(
            (new CertificationLessonProgressResource($progress->loadMissing('lesson')))->resolve($request),
        );
    }

    public function downloadResource(
        Request $request,
        CertificationEnrollment $enrollment,
        CertificationLesson $lesson,
        CertificationResource $resource,
    ): StreamedResponse {
        return $this->learning->downloadResource(
            $request->user(),
            $enrollment,
            $lesson,
            $resource,
        );
    }
}
