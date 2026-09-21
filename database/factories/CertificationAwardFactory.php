<?php

namespace Database\Factories;

use App\Enums\CertificationAwardStatus;
use App\Models\CertificationAssessmentAttempt;
use App\Models\CertificationAward;
use App\Models\CertificationEnrollment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CertificationAward>
 */
class CertificationAwardFactory extends Factory
{
    protected $model = CertificationAward::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => CertificationAwardStatus::Awarded,
            'awarded_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (CertificationAward $award): void {
            if ($award->enrollment_id === null) {
                $enrollment = CertificationEnrollment::factory()->create();
                $award->enrollment_id = $enrollment->id;
                $award->user_id = $enrollment->user_id;
                $award->programme_id = $enrollment->programme_id;
                $award->programme_version_id = $enrollment->programme_version_id;
            }

            if ($award->assessment_attempt_id === null) {
                $enrollment = CertificationEnrollment::query()->findOrFail($award->enrollment_id);
                $attempt = CertificationAssessmentAttempt::factory()->create([
                    'enrollment_id' => $enrollment->id,
                    'programme_version_id' => $enrollment->programme_version_id,
                ]);
                $award->assessment_attempt_id = $attempt->id;
            }

            if ($award->user_id === null) {
                $enrollment = CertificationEnrollment::query()->findOrFail($award->enrollment_id);
                $award->user_id = $enrollment->user_id;
                $award->programme_id = $enrollment->programme_id;
                $award->programme_version_id = $enrollment->programme_version_id;
            }
        });
    }
}
