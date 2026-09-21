<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CertificationProgrammeResource;
use App\Models\CertificationProgramme;
use App\Services\Certification\CertificationProgrammeCatalogueService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CertificationProgrammeController extends Controller
{
    public function __construct(
        private readonly CertificationProgrammeCatalogueService $catalogue,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $items = $this->catalogue->index()
            ->map(fn (CertificationProgramme $programme) => (new CertificationProgrammeResource($programme, false))->resolve($request))
            ->values()
            ->all();

        return ApiResponse::success($items);
    }

    public function show(Request $request, CertificationProgramme $programme): JsonResponse
    {
        $programme = $this->catalogue->show($programme);

        return ApiResponse::success(
            (new CertificationProgrammeResource($programme, false))->resolve($request),
        );
    }
}
