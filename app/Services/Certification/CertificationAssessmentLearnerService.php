<?php

namespace App\Services\Certification;

use App\Models\CertificationAssessment;
use App\Models\CertificationEnrollment;
use App\Models\CertificationProgrammeVersion;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;

class CertificationAssessmentLearnerService
{
    public function __construct(
        private readonly CertificationLearningService $learning,
    ) {}

    /**
     * @return array{
     *     enrollment: CertificationEnrollment,
     *     programme_version: CertificationProgrammeVersion,
     *     assessment: CertificationAssessment,
     *     available: bool,
     *     assessment_eligibility: array<string, mixed>
     * }
     */
    public function showForEnrollment(User $user, CertificationEnrollment $enrollment): array
    {
        $enrollment = $this->learning->assertOwnedActiveEnrollment($user, $enrollment);
        $version = CertificationProgrammeVersion::query()
            ->whereKey($enrollment->programme_version_id)
            ->first();

        if ($version === null || (int) $version->programme_id !== (int) $enrollment->programme_id) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::SERVER_ERROR,
                'Enrollment programme version is unavailable.',
                500,
            ));
        }

        $assessment = CertificationAssessment::query()
            ->where('programme_version_id', $version->id)
            ->with(['questions.options'])
            ->first();

        if ($assessment === null) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::NOT_FOUND,
                'The requested resource was not found.',
                404,
            ));
        }

        $eligibility = $this->learning->assessmentEligibilityForEnrollment($enrollment);

        return [
            'enrollment' => $enrollment->loadMissing(['programme', 'programmeVersion']),
            'programme_version' => $version,
            'assessment' => $assessment,
            'available' => (bool) $eligibility['eligible'],
            'assessment_eligibility' => $eligibility,
        ];
    }
}
