<?php

namespace Database\Factories;

use App\Enums\CertificationLessonProgressStatus;
use App\Models\CertificationEnrollment;
use App\Models\CertificationLesson;
use App\Models\CertificationLessonProgress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CertificationLessonProgress>
 */
class CertificationLessonProgressFactory extends Factory
{
    protected $model = CertificationLessonProgress::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enrollment_id' => CertificationEnrollment::factory(),
            'lesson_id' => CertificationLesson::factory(),
            'status' => CertificationLessonProgressStatus::Completed,
            'started_at' => now(),
            'completed_at' => now(),
        ];
    }
}
