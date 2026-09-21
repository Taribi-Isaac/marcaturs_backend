<?php

namespace Database\Factories;

use App\Enums\CertificationProgrammeVersionStatus;
use App\Models\CertificationProgramme;
use App\Models\CertificationProgrammeVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CertificationProgrammeVersion>
 */
class CertificationProgrammeVersionFactory extends Factory
{
    protected $model = CertificationProgrammeVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'programme_id' => CertificationProgramme::factory(),
            'version_number' => 1,
            'status' => CertificationProgrammeVersionStatus::Draft,
            'fee_amount_minor' => null,
            'fee_currency' => 'NGN',
            'pass_mark_percent' => null,
            'published_at' => null,
            'unpublished_at' => null,
            'created_by_user_id' => User::factory()->admin(),
        ];
    }

    public function publishable(): static
    {
        return $this->state(fn () => [
            // NON-PRODUCTION EXAMPLE ONLY — not a launch fee or approved pass mark.
            'fee_amount_minor' => 25_000_00,
            'fee_currency' => 'NGN',
            'pass_mark_percent' => '80.00',
        ]);
    }

    public function published(): static
    {
        return $this->publishable()->state(fn () => [
            'status' => CertificationProgrammeVersionStatus::Published,
            'published_at' => now(),
        ]);
    }
}
