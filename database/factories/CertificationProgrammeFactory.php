<?php

namespace Database\Factories;

use App\Enums\CertificationProgrammeStatus;
use App\Models\CertificationProgramme;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CertificationProgramme>
 */
class CertificationProgrammeFactory extends Factory
{
    protected $model = CertificationProgramme::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Ambassador Professional Certification',
            'description' => 'NON-PRODUCTION EXAMPLE ONLY — programme description placeholder.',
            'learning_objectives' => null,
            'status' => CertificationProgrammeStatus::Draft,
            'created_by_user_id' => User::factory()->admin(),
            'current_published_version_id' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => CertificationProgrammeStatus::Published,
        ]);
    }
}
