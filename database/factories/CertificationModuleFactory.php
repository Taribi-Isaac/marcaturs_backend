<?php

namespace Database\Factories;

use App\Models\CertificationModule;
use App\Models\CertificationProgrammeVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CertificationModule>
 */
class CertificationModuleFactory extends Factory
{
    protected $model = CertificationModule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'programme_version_id' => CertificationProgrammeVersion::factory(),
            'title' => 'NON-PRODUCTION EXAMPLE — Module',
            'description' => null,
            'sort_order' => 1,
        ];
    }
}
