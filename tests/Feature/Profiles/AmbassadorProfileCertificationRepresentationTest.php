<?php

namespace Tests\Feature\Profiles;

use App\Enums\AdminStaffRole;
use App\Enums\CertificationAwardStatus;
use App\Enums\CertificationCertificateArtifactStatus;
use App\Enums\CertificationCertificateStatus;
use App\Enums\CertificationProgrammeStatus;
use App\Models\AmbassadorProfile;
use App\Models\CertificationAward;
use App\Models\CertificationCertificate;
use App\Models\CertificationEnrollment;
use App\Models\CertificationProgramme;
use App\Models\CertificationProgrammeVersion;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use App\Support\Certification\AmbassadorCertificationRepresentation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AmbassadorProfileCertificationRepresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_uncertified_ambassador_profile_shows_empty_certification(): void
    {
        $user = User::factory()->ambassador()->create();
        AmbassadorProfile::factory()->for($user)->create(['display_name' => 'Not Certified Yet']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/ambassadors/me')
            ->assertOk()
            ->assertJsonPath('data.certification.is_certified', false)
            ->assertJsonPath('data.certification.label', null)
            ->assertJsonPath('data.certification.awards', [])
            ->assertJsonMissingPath('data.verification_status')
            ->assertJsonMissingPath('data.certification_status')
            ->assertJsonMissingPath('data.certification.awards.0.artifact_path')
            ->assertJsonMissingPath('data.certification.awards.0.disk');
    }

    public function test_enrollment_without_award_is_not_certified(): void
    {
        $user = User::factory()->ambassador()->create();
        AmbassadorProfile::factory()->for($user)->create();
        $programme = CertificationProgramme::factory()->create([
            'status' => CertificationProgrammeStatus::Published,
        ]);
        $version = CertificationProgrammeVersion::factory()->for($programme, 'programme')->published()->create();
        CertificationEnrollment::factory()->create([
            'user_id' => $user->id,
            'programme_id' => $programme->id,
            'programme_version_id' => $version->id,
        ]);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/ambassadors/me')
            ->assertOk()
            ->assertJsonPath('data.certification.is_certified', false)
            ->assertJsonCount(0, 'data.certification.awards');
    }

    public function test_award_shows_certified_representation_with_historical_version(): void
    {
        $user = User::factory()->ambassador()->create();
        AmbassadorProfile::factory()->for($user)->create(['display_name' => 'Certified Promo']);

        $programme = CertificationProgramme::factory()->create([
            'name' => 'NON-PRODUCTION Ambassador Professional Certification',
            'status' => CertificationProgrammeStatus::Published,
        ]);
        $version1 = CertificationProgrammeVersion::factory()->for($programme, 'programme')->published()->create([
            'version_number' => 1,
        ]);
        $enrollment = CertificationEnrollment::factory()->create([
            'user_id' => $user->id,
            'programme_id' => $programme->id,
            'programme_version_id' => $version1->id,
        ]);
        $award = CertificationAward::factory()->create([
            'user_id' => $user->id,
            'programme_id' => $programme->id,
            'programme_version_id' => $version1->id,
            'enrollment_id' => $enrollment->id,
            'status' => CertificationAwardStatus::Awarded,
        ]);
        CertificationCertificate::factory()->create([
            'award_id' => $award->id,
            'status' => CertificationCertificateStatus::Issued,
            'recipient_name' => $user->name,
            'programme_name' => 'NON-PRODUCTION Ambassador Professional Certification',
            'programme_version_number' => 1,
            'artifact_status' => CertificationCertificateArtifactStatus::PendingGeneration,
        ]);

        // Later programme rename + new published version must not rewrite historical profile cert.
        $programme->forceFill(['name' => 'RENAMED LIVE PROGRAMME'])->save();
        CertificationProgrammeVersion::factory()->for($programme, 'programme')->published()->create([
            'version_number' => 2,
        ]);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/ambassadors/me')
            ->assertOk()
            ->assertJsonPath('data.certification.is_certified', true)
            ->assertJsonPath('data.certification.label', AmbassadorCertificationRepresentation::LABEL_CERTIFIED)
            ->assertJsonPath('data.certification.awards.0.id', $award->id)
            ->assertJsonPath('data.certification.awards.0.programme_name', 'NON-PRODUCTION Ambassador Professional Certification')
            ->assertJsonPath('data.certification.awards.0.programme_version_number', 1)
            ->assertJsonPath('data.certification.awards.0.programme_version_id', $version1->id)
            ->assertJsonPath('data.certification.awards.0.certificate_id', CertificationCertificate::query()->value('id'))
            ->assertJsonMissingPath('data.certification.awards.0.certificate_number')
            ->assertJsonMissingPath('data.certification.awards.0.artifact_path')
            ->assertJsonMissingPath('data.certification.awards.0.download_url');
    }

    public function test_missing_pdf_artifact_does_not_hide_certification(): void
    {
        $user = User::factory()->ambassador()->create();
        AmbassadorProfile::factory()->for($user)->create();
        $programme = CertificationProgramme::factory()->create([
            'status' => CertificationProgrammeStatus::Published,
        ]);
        $version = CertificationProgrammeVersion::factory()->for($programme, 'programme')->published()->create([
            'version_number' => 1,
        ]);
        $enrollment = CertificationEnrollment::factory()->create([
            'user_id' => $user->id,
            'programme_id' => $programme->id,
            'programme_version_id' => $version->id,
        ]);
        $award = CertificationAward::factory()->create([
            'user_id' => $user->id,
            'programme_id' => $programme->id,
            'programme_version_id' => $version->id,
            'enrollment_id' => $enrollment->id,
        ]);
        // Award without Certificate record still qualifies (Award = qualification).
        unset($award);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/ambassadors/me')
            ->assertOk()
            ->assertJsonPath('data.certification.is_certified', true)
            ->assertJsonPath('data.certification.awards.0.certificate_id', null)
            ->assertJsonPath('data.certification.awards.0.programme_version_number', 1);
    }

    public function test_admin_user_detail_includes_certification_on_ambassador_profile(): void
    {
        $ambassador = User::factory()->ambassador()->create();
        AmbassadorProfile::factory()->for($ambassador)->create(['display_name' => 'Admin View']);
        $programme = CertificationProgramme::factory()->create([
            'name' => 'NON-PRODUCTION Programme',
            'status' => CertificationProgrammeStatus::Published,
        ]);
        $version = CertificationProgrammeVersion::factory()->for($programme, 'programme')->published()->create([
            'version_number' => 1,
        ]);
        $enrollment = CertificationEnrollment::factory()->create([
            'user_id' => $ambassador->id,
            'programme_id' => $programme->id,
            'programme_version_id' => $version->id,
        ]);
        CertificationAward::factory()->create([
            'user_id' => $ambassador->id,
            'programme_id' => $programme->id,
            'programme_version_id' => $version->id,
            'enrollment_id' => $enrollment->id,
        ]);

        $admin = User::factory()->adminStaff(AdminStaffRole::Operations)->create();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/users/'.$ambassador->id)
            ->assertOk()
            ->assertJsonPath('data.profile.certification.is_certified', true)
            ->assertJsonPath('data.profile.certification.label', AmbassadorCertificationRepresentation::LABEL_CERTIFIED)
            ->assertJsonPath('data.verification_status', fn ($value) => is_string($value));
    }

    public function test_business_cannot_read_ambassador_me_certification(): void
    {
        Sanctum::actingAs(User::factory()->business()->create());
        $this->getJson('/api/v1/ambassadors/me')
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);
    }

    public function test_restricted_ambassador_cannot_read_profile_certification(): void
    {
        $user = User::factory()->ambassador()->restricted()->create();
        AmbassadorProfile::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/ambassadors/me')
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);
    }
}
