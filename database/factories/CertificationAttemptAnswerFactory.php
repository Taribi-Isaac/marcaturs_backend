<?php

namespace Database\Factories;

use App\Models\CertificationAssessmentAttempt;
use App\Models\CertificationAttemptAnswer;
use App\Models\CertificationQuestion;
use App\Models\CertificationQuestionOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CertificationAttemptAnswer>
 */
class CertificationAttemptAnswerFactory extends Factory
{
    protected $model = CertificationAttemptAnswer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attempt_id' => CertificationAssessmentAttempt::factory(),
            'question_id' => CertificationQuestion::factory(),
            'selected_option_id' => CertificationQuestionOption::factory(),
            'is_correct' => null,
        ];
    }
}
