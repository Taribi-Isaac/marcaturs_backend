<?php

namespace Database\Factories;

use App\Enums\CertificationCertificateArtifactStatus;
use App\Enums\CertificationCertificateStatus;
use App\Models\CertificationAward;
use App\Models\CertificationCertificate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CertificationCertificate>
 */
class CertificationCertificateFactory extends Factory
{
    protected $model = CertificationCertificate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'award_id' => CertificationAward::factory(),
            'certificate_number' => 'mhcert_'.strtolower((string) Str::ulid()),
            'status' => CertificationCertificateStatus::Issued,
            'issued_at' => now(),
            'recipient_name' => 'NON-PRODUCTION Ambassador',
            'programme_name' => 'NON-PRODUCTION Programme',
            'programme_version_number' => 1,
            'issuer_name' => 'MarcatursHub',
            'artifact_status' => CertificationCertificateArtifactStatus::PendingGeneration,
        ];
    }

    public function generated(): static
    {
        return $this->state(fn () => [
            'artifact_status' => CertificationCertificateArtifactStatus::Generated,
            'artifact_disk' => (string) config('certification.certificate_disk'),
            'artifact_path' => 'certification/certificates/test/'.Str::ulid().'.pdf',
            'artifact_generated_at' => now(),
            'artifact_failed_at' => null,
            'artifact_error_code' => null,
        ]);
    }
}
