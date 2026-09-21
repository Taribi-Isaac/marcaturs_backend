<?php

namespace Database\Factories;

use App\Enums\CertificationEnrollmentStatus;
use App\Enums\CertificationProgrammeStatus;
use App\Enums\PlatformPaymentPurpose;
use App\Enums\PlatformPaymentStatus;
use App\Models\CertificationEnrollment;
use App\Models\CertificationProgramme;
use App\Models\CertificationProgrammeVersion;
use App\Models\PlatformPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CertificationEnrollment>
 */
class CertificationEnrollmentFactory extends Factory
{
    protected $model = CertificationEnrollment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => CertificationEnrollmentStatus::Active,
            'fee_amount_minor' => 1500000,
            'fee_currency' => 'NGN',
            'enrolled_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (CertificationEnrollment $enrollment): void {
            if ($enrollment->user_id === null) {
                $enrollment->user_id = User::factory()->ambassador()->create()->id;
            }

            if ($enrollment->programme_id === null) {
                $programme = CertificationProgramme::factory()->create([
                    'status' => CertificationProgrammeStatus::Published,
                ]);
                $version = CertificationProgrammeVersion::factory()->for($programme, 'programme')->published()->create();
                $programme->current_published_version_id = $version->id;
                $programme->save();
                $enrollment->programme_id = $programme->id;
                $enrollment->programme_version_id = $version->id;
            }

            if ($enrollment->programme_version_id === null) {
                $enrollment->programme_version_id = CertificationProgrammeVersion::factory()
                    ->for(CertificationProgramme::query()->findOrFail($enrollment->programme_id), 'programme')
                    ->published()
                    ->create()
                    ->id;
            }

            if ($enrollment->platform_payment_id === null) {
                $payment = new PlatformPayment;
                $payment->user_id = $enrollment->user_id;
                $payment->campaign_id = null;
                $payment->certification_programme_id = $enrollment->programme_id;
                $payment->certification_programme_version_id = $enrollment->programme_version_id;
                $payment->purpose = PlatformPaymentPurpose::CertificationEnrollment;
                $payment->provider = 'paystack';
                $payment->reference = 'mh_cert_'.strtolower((string) Str::ulid());
                $payment->amount_minor = $enrollment->fee_amount_minor;
                $payment->currency = $enrollment->fee_currency;
                $payment->status = PlatformPaymentStatus::Paid;
                $payment->paid_at = now();
                $payment->save();
                $enrollment->platform_payment_id = $payment->id;
            }
        });
    }
}
