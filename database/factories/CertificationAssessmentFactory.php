<?php

namespace Database\Factories;

use App\Models\CertificationAssessment;
use App\Models\CertificationProgrammeVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CertificationAssessment>
 */
class CertificationAssessmentFactory extends Factory
{
    protected $model = CertificationAssessment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'programme_version_id' => CertificationProgrammeVersion::factory(),
            'title' => 'NON-PRODUCTION Final Assessment',
            'instructions' => 'Synthetic non-production assessment instructions only.',
            'configuration' => null,
        ];
    }
}
