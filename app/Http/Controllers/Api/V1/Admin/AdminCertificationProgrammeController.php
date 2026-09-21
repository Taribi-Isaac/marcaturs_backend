<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Certification\StoreCertificationProgrammeRequest;
use App\Http\Requests\Api\V1\Admin\Certification\StoreCertificationProgrammeVersionRequest;
use App\Http\Requests\Api\V1\Admin\Certification\UpdateCertificationProgrammeRequest;
use App\Http\Requests\Api\V1\Admin\Certification\UpdateCertificationProgrammeVersionRequest;
use App\Http\Resources\Api\V1\CertificationProgrammeResource;
use App\Http\Resources\Api\V1\CertificationProgrammeVersionResource;
use App\Models\CertificationProgramme;
use App\Models\CertificationProgrammeVersion;
use App\Services\Certification\CertificationProgrammeAdminService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCertificationProgrammeController extends Controller
{
    public function __construct(
        private readonly CertificationProgrammeAdminService $programmes,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $items = $this->programmes->index($request->user())
            ->map(fn (CertificationProgramme $programme) => (new CertificationProgrammeResource($programme, true))->resolve($request))
            ->values()
            ->all();

        return ApiResponse::success($items);
    }

    public function store(StoreCertificationProgrammeRequest $request): JsonResponse
    {
        $programme = $this->programmes->create($request->user(), $request->validated());

        return ApiResponse::success(
            (new CertificationProgrammeResource($programme, true))->resolve($request),
            201,
        );
    }

    public function show(Request $request, CertificationProgramme $programme): JsonResponse
    {
        $programme = $this->programmes->show($request->user(), $programme);

        return ApiResponse::success(
            (new CertificationProgrammeResource($programme, true))->resolve($request),
        );
    }

    public function update(UpdateCertificationProgrammeRequest $request, CertificationProgramme $programme): JsonResponse
    {
        $updated = $this->programmes->update($request->user(), $programme, $request->validated());

        return ApiResponse::success(
            (new CertificationProgrammeResource($updated, true))->resolve($request),
        );
    }

    public function indexVersions(Request $request, CertificationProgramme $programme): JsonResponse
    {
        $items = $this->programmes->indexVersions($request->user(), $programme)
            ->map(fn (CertificationProgrammeVersion $version) => (new CertificationProgrammeVersionResource($version, true))->resolve($request))
            ->values()
            ->all();

        return ApiResponse::success($items);
    }

    public function storeVersion(
        StoreCertificationProgrammeVersionRequest $request,
        CertificationProgramme $programme,
    ): JsonResponse {
        $version = $this->programmes->createVersion($request->user(), $programme, $request->validated());

        return ApiResponse::success(
            (new CertificationProgrammeVersionResource($version, true))->resolve($request),
            201,
        );
    }

    public function showVersion(
        Request $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
    ): JsonResponse {
        $version = $this->programmes->showVersion($request->user(), $programme, $version);

        return ApiResponse::success(
            (new CertificationProgrammeVersionResource($version, true))->resolve($request),
        );
    }

    public function updateVersion(
        UpdateCertificationProgrammeVersionRequest $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
    ): JsonResponse {
        $updated = $this->programmes->updateVersion(
            $request->user(),
            $programme,
            $version,
            $request->validated(),
        );

        return ApiResponse::success(
            (new CertificationProgrammeVersionResource($updated, true))->resolve($request),
        );
    }

    public function publishVersion(
        Request $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
    ): JsonResponse {
        $published = $this->programmes->publishVersion($request->user(), $programme, $version);

        return ApiResponse::success(
            (new CertificationProgrammeVersionResource($published, true))->resolve($request),
        );
    }

    public function unpublishVersion(
        Request $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
    ): JsonResponse {
        $unpublished = $this->programmes->unpublishVersion($request->user(), $programme, $version);

        return ApiResponse::success(
            (new CertificationProgrammeVersionResource($unpublished, true))->resolve($request),
        );
    }
}
