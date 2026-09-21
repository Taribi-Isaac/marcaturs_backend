<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CertificationCertificateResource;
use App\Models\CertificationCertificate;
use App\Models\CertificationEnrollment;
use App\Services\Certification\CertificationCertificateArtifactService;
use App\Services\Certification\CertificationCertificateService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminCertificationCertificateController extends Controller
{
    public function __construct(
        private readonly CertificationCertificateService $certificates,
        private readonly CertificationCertificateArtifactService $artifacts,
    ) {}

    public function indexForEnrollment(Request $request, CertificationEnrollment $enrollment): JsonResponse
    {
        return ApiResponse::success(
            $this->certificates->adminIndexForEnrollment($request->user(), (int) $enrollment->id)
                ->map(fn (CertificationCertificate $certificate) => (new CertificationCertificateResource($certificate, admin: true))->resolve($request))
                ->values()
                ->all(),
        );
    }

    public function show(Request $request, CertificationCertificate $certificate): JsonResponse
    {
        $certificate = $this->certificates->adminShow($request->user(), $certificate);

        return ApiResponse::success(
            (new CertificationCertificateResource($certificate, admin: true))->resolve($request),
        );
    }

    public function download(Request $request, CertificationCertificate $certificate): StreamedResponse
    {
        return $this->artifacts->downloadForAdmin($request->user(), $certificate);
    }

    public function retryArtifact(Request $request, CertificationCertificate $certificate): JsonResponse
    {
        $certificate = $this->artifacts->retryGeneration($request->user(), $certificate);

        return ApiResponse::success(
            (new CertificationCertificateResource($certificate->load(['award.user', 'award.programme', 'award.programmeVersion']), admin: true))->resolve($request),
        );
    }
}
