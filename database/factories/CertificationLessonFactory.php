<?php

namespace Database\Factories;

use App\Enums\CertificationLessonContentType;
use App\Models\CertificationLesson;
use App\Models\CertificationModule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CertificationLesson>
 */
class CertificationLessonFactory extends Factory
{
    protected $model = CertificationLesson::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'module_id' => CertificationModule::factory(),
            'title' => 'NON-PRODUCTION EXAMPLE — Lesson',
            'description' => null,
            'content_type' => CertificationLessonContentType::Text,
            'is_required' => true,
            'sort_order' => 1,
        ];
    }
}
