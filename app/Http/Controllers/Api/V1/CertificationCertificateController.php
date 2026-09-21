<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CertificationCertificateResource;
use App\Models\CertificationCertificate;
use App\Services\Certification\CertificationCertificateArtifactService;
use App\Services\Certification\CertificationCertificateService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CertificationCertificateController extends Controller
{
    public function __construct(
        private readonly CertificationCertificateService $certificates,
        private readonly CertificationCertificateArtifactService $artifacts,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->certificates->indexForAmbassador($request->user())
                ->map(fn (CertificationCertificate $certificate) => (new CertificationCertificateResource($certificate))->resolve($request))
                ->values()
                ->all(),
        );
    }

    public function show(Request $request, CertificationCertificate $certificate): JsonResponse
    {
        $certificate = $this->certificates->showForAmbassador($request->user(), $certificate);

        return ApiResponse::success(
            (new CertificationCertificateResource($certificate))->resolve($request),
        );
    }

    public function download(Request $request, CertificationCertificate $certificate): StreamedResponse
    {
        return $this->artifacts->downloadForAmbassador($request->user(), $certificate);
    }
}
