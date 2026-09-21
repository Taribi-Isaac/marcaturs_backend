<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CertificationAssessment;
use App\Models\CertificationEnrollment;
use App\Models\CertificationProgrammeVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read array{
 *     enrollment: CertificationEnrollment,
 *     programme_version: CertificationProgrammeVersion,
 *     assessment: CertificationAssessment,
 *     available: bool,
 *     assessment_eligibility: array<string, mixed>
 * } $resource
 */
class CertificationLearnerAssessmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->resource;
        $available = (bool) $payload['available'];

        return [
            'enrollment' => (new CertificationEnrollmentResource($payload['enrollment']))->resolve($request),
            'programme_version' => [
                'id' => $payload['programme_version']->id,
                'programme_id' => $payload['programme_version']->programme_id,
                'version_number' => $payload['programme_version']->version_number,
                'status' => $payload['programme_version']->status->value,
            ],
            'available' => $available,
            'assessment_eligibility' => $payload['assessment_eligibility'],
            'assessment' => (new CertificationAssessmentResource(
                $payload['assessment'],
                admin: false,
                includeQuestions: $available,
            ))->resolve($request),
        ];
    }
}
