<?php

namespace Database\Factories;

use App\Enums\CertificationQuestionType;
use App\Models\CertificationAssessment;
use App\Models\CertificationQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CertificationQuestion>
 */
class CertificationQuestionFactory extends Factory
{
    protected $model = CertificationQuestion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'assessment_id' => CertificationAssessment::factory(),
            'prompt' => 'NON-PRODUCTION EXAMPLE — Which option is correct?',
            'type' => CertificationQuestionType::SingleChoice,
            'sort_order' => 1,
        ];
    }
}
