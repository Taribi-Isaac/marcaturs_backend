<?php

namespace Database\Factories;

use App\Enums\CertificationAssessmentAttemptStatus;
use App\Models\CertificationAssessment;
use App\Models\CertificationAssessmentAttempt;
use App\Models\CertificationEnrollment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CertificationAssessmentAttempt>
 */
class CertificationAssessmentAttemptFactory extends Factory
{
    protected $model = CertificationAssessmentAttempt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enrollment_id' => CertificationEnrollment::factory(),
            'assessment_id' => CertificationAssessment::factory(),
            'programme_version_id' => 1,
            'attempt_number' => 1,
            'status' => CertificationAssessmentAttemptStatus::InProgress,
            'started_at' => now(),
            'submitted_at' => null,
            'correct_count' => null,
            'total_questions' => null,
            'score_percent' => null,
            'passed' => null,
            'pass_mark_percent' => '80.00',
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (CertificationAssessmentAttempt $attempt): void {
            if ($attempt->programme_version_id === 1 || $attempt->programme_version_id === null) {
                $assessment = CertificationAssessment::query()->find($attempt->assessment_id);
                if ($assessment !== null) {
                    $attempt->programme_version_id = $assessment->programme_version_id;
                }
            }
        });
    }
}
