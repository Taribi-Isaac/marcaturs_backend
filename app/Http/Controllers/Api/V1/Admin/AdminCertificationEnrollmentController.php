<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CertificationEnrollmentResource;
use App\Models\CertificationEnrollment;
use App\Services\Certification\CertificationEnrollmentService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCertificationEnrollmentController extends Controller
{
    public function __construct(
        private readonly CertificationEnrollmentService $enrollments,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $programmeId = $request->query('programme_id');

        $items = $this->enrollments->adminIndex(
            $request->user(),
            is_numeric($programmeId) ? (int) $programmeId : null,
        );

        return ApiResponse::success(
            $items
                ->map(fn (CertificationEnrollment $enrollment) => (new CertificationEnrollmentResource($enrollment, true))->resolve($request))
                ->values()
                ->all(),
        );
    }

    public function show(Request $request, CertificationEnrollment $enrollment): JsonResponse
    {
        $enrollment = $this->enrollments->adminShow($request->user(), $enrollment);

        return ApiResponse::success(
            (new CertificationEnrollmentResource($enrollment, true))->resolve($request),
        );
    }
}
