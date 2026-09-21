<?php

namespace Database\Factories;

use App\Enums\CertificationResourceType;
use App\Models\CertificationLesson;
use App\Models\CertificationResource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CertificationResource>
 */
class CertificationResourceFactory extends Factory
{
    protected $model = CertificationResource::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lesson_id' => CertificationLesson::factory(),
            'type' => CertificationResourceType::Text,
            'title' => 'NON-PRODUCTION EXAMPLE — Resource',
            'sort_order' => 1,
            'body_text' => 'Synthetic non-production body text only.',
            'external_url' => null,
            'disk' => null,
            'path' => null,
            'original_filename' => null,
            'mime_type' => null,
            'size_bytes' => null,
        ];
    }
}
