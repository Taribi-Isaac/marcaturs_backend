<?php

namespace Database\Factories;

use App\Models\CertificationQuestion;
use App\Models\CertificationQuestionOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CertificationQuestionOption>
 */
class CertificationQuestionOptionFactory extends Factory
{
    protected $model = CertificationQuestionOption::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'question_id' => CertificationQuestion::factory(),
            'label' => 'NON-PRODUCTION option',
            'is_correct' => false,
            'sort_order' => 1,
        ];
    }
}
