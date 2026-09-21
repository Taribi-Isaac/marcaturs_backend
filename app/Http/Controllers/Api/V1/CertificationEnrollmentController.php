<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Certification\VerifyCertificationPurchaseRequest;
use App\Http\Resources\Api\V1\CertificationEnrollmentResource;
use App\Http\Resources\Api\V1\PlatformPaymentResource;
use App\Models\CertificationEnrollment;
use App\Models\CertificationProgramme;
use App\Services\Certification\CertificationEnrollmentService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CertificationEnrollmentController extends Controller
{
    public function __construct(
        private readonly CertificationEnrollmentService $enrollments,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            CertificationEnrollmentResource::collection(
                $this->enrollments->indexForAmbassador($request->user()),
            )->resolve($request),
        );
    }

    public function show(Request $request, CertificationEnrollment $enrollment): JsonResponse
    {
        $enrollment = $this->enrollments->showForAmbassador($request->user(), $enrollment);

        return ApiResponse::success(
            (new CertificationEnrollmentResource($enrollment))->resolve($request),
        );
    }

    public function initialize(Request $request, CertificationProgramme $programme): JsonResponse
    {
        $result = $this->enrollments->initialize($request->user(), $programme);

        return ApiResponse::success([
            'authorization_url' => $result['authorization_url'],
            'access_code' => $result['access_code'],
            'payment' => (new PlatformPaymentResource($result['payment']))->resolve($request),
        ], 201);
    }

    public function verify(VerifyCertificationPurchaseRequest $request): JsonResponse
    {
        $enrollment = $this->enrollments->confirmOwnedReference(
            $request->user(),
            (string) $request->validated('reference'),
        );

        return ApiResponse::success(
            (new CertificationEnrollmentResource($enrollment))->resolve($request),
        );
    }
}
