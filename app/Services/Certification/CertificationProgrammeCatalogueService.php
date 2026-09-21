<?php

namespace App\Services\Certification;

use App\Enums\CertificationProgrammeStatus;
use App\Models\CertificationProgramme;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;

class CertificationProgrammeCatalogueService
{
    /**
     * @return Collection<int, CertificationProgramme>
     */
    public function index(): Collection
    {
        return CertificationProgramme::query()
            ->where('status', CertificationProgrammeStatus::Published->value)
            ->whereNotNull('current_published_version_id')
            ->with('currentPublishedVersion')
            ->orderBy('name')
            ->get();
    }

    public function show(CertificationProgramme $programme): CertificationProgramme
    {
        if (
            $programme->status !== CertificationProgrammeStatus::Published
            || $programme->current_published_version_id === null
        ) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::NOT_FOUND,
                'The requested resource was not found.',
                404,
            ));
        }

        return $programme->load('currentPublishedVersion');
    }
}
