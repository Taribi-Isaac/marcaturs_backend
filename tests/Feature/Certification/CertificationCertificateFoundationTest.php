<?php

namespace Tests\Feature\Certification;

use App\Enums\AccountStatus;
use App\Enums\AdminStaffRole;
use App\Enums\CertificationAdminEventAction;
use App\Enums\CertificationCertificateArtifactStatus;
use App\Enums\CertificationCertificateStatus;
use App\Enums\CertificationLessonContentType;
use App\Enums\CertificationLessonProgressStatus;
use App\Enums\CertificationProgrammeStatus;
use App\Enums\CertificationQuestionType;
use App\Enums\NotificationType;
use App\Models\CertificationAdminEvent;
use App\Models\CertificationAssessment;
use App\Models\CertificationAward;
use App\Models\CertificationCertificate;
use App\Models\CertificationEnrollment;
use App\Models\CertificationLesson;
use App\Models\CertificationLessonProgress;
use App\Models\CertificationModule;
use App\Models\CertificationProgramme;
use App\Models\CertificationProgrammeVersion;
use App\Models\CertificationQuestion;
use App\Models\CertificationQuestionOption;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CertificationCertificateFoundationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{
     *     ambassador: User,
     *     programme: CertificationProgramme,
     *     version: CertificationProgrammeVersion,
     *     enrollment: CertificationEnrollment,
     *     questions: list<CertificationQuestion>,
     *     correctOptionIds: list<int>,
     *     wrongOptionIds: list<int>
     * }
     */
    private function readyStack(): array
    {
        $ambassador = User::factory()->ambassador()->create([
            'name' => 'NON-PRODUCTION Certified Ambassador',
        ]);
        $programme = CertificationProgramme::factory()->create([
            'name' => 'NON-PRODUCTION Ambassador Professional Certification',
            'status' => CertificationProgrammeStatus::Published,
        ]);
        $version = CertificationProgrammeVersion::factory()->for($programme, 'programme')->published()->create([
            'version_number' => 1,
            'pass_mark_percent' => '50.00',
        ]);
        $programme->current_published_version_id = $version->id;
        $programme->save();

        $module = CertificationModule::factory()->for($version, 'version')->create(['sort_order' => 1]);
        $required = CertificationLesson::factory()->for($module, 'module')->create([
            'is_required' => true,
            'content_type' => CertificationLessonContentType::Text,
            'sort_order' => 1,
        ]);

        $assessment = CertificationAssessment::factory()->for($version, 'programmeVersion')->create();

        $questions = [];
        $correctOptionIds = [];
        $wrongOptionIds = [];
        for ($i = 1; $i <= 2; $i++) {
            $question = CertificationQuestion::factory()->for($assessment, 'assessment')->create([
                'type' => CertificationQuestionType::SingleChoice,
                'sort_order' => $i,
            ]);
            $wrong = CertificationQuestionOption::factory()->for($question, 'question')->create([
                'is_correct' => false,
                'sort_order' => 1,
            ]);
            $correct = CertificationQuestionOption::factory()->for($question, 'question')->create([
                'is_correct' => true,
                'sort_order' => 2,
            ]);
            $questions[] = $question;
            $correctOptionIds[] = $correct->id;
            $wrongOptionIds[] = $wrong->id;
        }

        $enrollment = CertificationEnrollment::factory()->create([
            'user_id' => $ambassador->id,
            'programme_id' => $programme->id,
            'programme_version_id' => $version->id,
        ]);
        CertificationLessonProgress::factory()->create([
            'enrollment_id' => $enrollment->id,
            'lesson_id' => $required->id,
            'status' => CertificationLessonProgressStatus::Completed,
        ]);

        return compact(
            'ambassador',
            'programme',
            'version',
            'enrollment',
            'questions',
            'correctOptionIds',
            'wrongOptionIds',
        );
    }

    /**
     * @param  list<CertificationQuestion>  $questions
     * @param  list<int>  $optionIds
     * @return list<array{question_id: int, selected_option_id: int}>
     */
    private function answersFor(array $questions, array $optionIds): array
    {
        $payload = [];
        foreach ($questions as $index => $question) {
            $payload[] = [
                'question_id' => $question->id,
                'selected_option_id' => $optionIds[$index],
            ];
        }

        return $payload;
    }

    private function passAssessment(array $stack): int
    {
        Storage::fake((string) config('certification.certificate_disk'));
        Sanctum::actingAs($stack['ambassador']);
        $base = "/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment/attempts";
        $attemptId = (int) $this->postJson($base)->assertCreated()->json('data.id');
        $this->postJson("{$base}/{$attemptId}/submit", [
            'answers' => $this->answersFor($stack['questions'], $stack['correctOptionIds']),
        ])->assertOk()->assertJsonPath('data.passed', true);

        return $attemptId;
    }

    public function test_failed_attempt_does_not_create_certificate(): void
    {
        $stack = $this->readyStack();
        Sanctum::actingAs($stack['ambassador']);
        $base = "/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment/attempts";
        $attemptId = (int) $this->postJson($base)->assertCreated()->json('data.id');
        $this->postJson("{$base}/{$attemptId}/submit", [
            'answers' => $this->answersFor($stack['questions'], $stack['wrongOptionIds']),
        ])->assertOk()->assertJsonPath('data.passed', false);

        $this->assertSame(0, CertificationAward::query()->count());
        $this->assertSame(0, CertificationCertificate::query()->count());
    }

    public function test_passing_creates_one_certificate_with_snapshots_and_notification(): void
    {
        $stack = $this->readyStack();
        $this->passAssessment($stack);

        $this->assertSame(1, CertificationAward::query()->count());
        $this->assertSame(1, CertificationCertificate::query()->count());

        $certificate = CertificationCertificate::query()->firstOrFail();
        $award = CertificationAward::query()->firstOrFail();

        $this->assertSame($award->id, $certificate->award_id);
        $this->assertSame(CertificationCertificateStatus::Issued, $certificate->status);
        $this->assertSame('NON-PRODUCTION Certified Ambassador', $certificate->recipient_name);
        $this->assertSame('NON-PRODUCTION Ambassador Professional Certification', $certificate->programme_name);
        $this->assertSame(1, $certificate->programme_version_number);
        $this->assertSame('MarcatursHub', $certificate->issuer_name);
        $this->assertNotEmpty($certificate->certificate_number);
        $this->assertStringStartsWith('mhcert_', $certificate->certificate_number);
        $this->assertSame(
            CertificationCertificateArtifactStatus::Generated,
            $certificate->artifact_status,
        );

        $this->assertTrue(
            CertificationAdminEvent::query()
                ->where('action', CertificationAdminEventAction::CertificateCreated)
                ->exists(),
        );

        $this->assertTrue(
            DatabaseNotification::query()
                ->where('notifiable_id', $award->user_id)
                ->where('data->notification_type', NotificationType::CertificationCertificateAvailable->value)
                ->exists(),
        );
    }

    public function test_certificate_is_idempotent_across_retakes_and_keeps_version_history(): void
    {
        $stack = $this->readyStack();
        $this->passAssessment($stack);
        $certificate = CertificationCertificate::query()->firstOrFail();
        $number = $certificate->certificate_number;
        $awardId = $certificate->award_id;

        Sanctum::actingAs($stack['ambassador']);
        $base = "/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment/attempts";
        $secondId = (int) $this->postJson($base)->assertCreated()->json('data.id');
        $this->postJson("{$base}/{$secondId}/submit", [
            'answers' => $this->answersFor($stack['questions'], $stack['correctOptionIds']),
        ])->assertOk();

        $this->assertSame(1, CertificationCertificate::query()->count());
        $this->assertSame($number, CertificationCertificate::query()->value('certificate_number'));
        $this->assertSame($awardId, CertificationCertificate::query()->value('award_id'));

        $admin = User::factory()->adminStaff(AdminStaffRole::Operations)->create();
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/certification/programmes/{$stack['programme']->id}/versions", [
            'fee_amount_minor' => 2500000,
            'pass_mark_percent' => 90,
        ])->assertCreated();
        $this->postJson("/api/v1/admin/certification/programmes/{$stack['programme']->id}/versions/2/publish")->assertOk();

        $stack['programme']->forceFill(['name' => 'RENAMED PROGRAMME'])->save();

        $fresh = $certificate->fresh();
        $this->assertSame(1, $fresh->programme_version_number);
        $this->assertSame('NON-PRODUCTION Ambassador Professional Certification', $fresh->programme_name);
        $this->assertSame($stack['version']->id, $fresh->award->programme_version_id);
    }

    public function test_ambassador_can_access_own_certificate_only(): void
    {
        $stack = $this->readyStack();
        $this->passAssessment($stack);
        $certificateId = (int) CertificationCertificate::query()->value('id');

        Sanctum::actingAs($stack['ambassador']);
        $this->getJson('/api/v1/certification/certificates')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $certificateId)
            ->assertJsonPath('data.0.status', CertificationCertificateStatus::Issued->value)
            ->assertJsonMissingPath('data.0.disk')
            ->assertJsonMissingPath('data.0.path');

        $this->getJson("/api/v1/certification/certificates/{$certificateId}")
            ->assertOk()
            ->assertJsonPath('data.recipient_name', 'NON-PRODUCTION Certified Ambassador');

        $other = User::factory()->ambassador()->create();
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/certification/certificates')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/certification/certificates/{$certificateId}")
            ->assertStatus(404);

        Sanctum::actingAs(User::factory()->business()->create());
        $this->getJson('/api/v1/certification/certificates')->assertStatus(403);
    }

    public function test_admin_can_inspect_certificates(): void
    {
        $stack = $this->readyStack();
        $this->passAssessment($stack);
        $certificateId = (int) CertificationCertificate::query()->value('id');

        $admin = User::factory()->adminStaff(AdminStaffRole::Operations)->create();
        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/admin/certification/enrollments/{$stack['enrollment']->id}/certificates")
            ->assertOk()
            ->assertJsonPath('data.0.id', $certificateId)
            ->assertJsonPath('data.0.award.user.id', $stack['ambassador']->id);

        $this->getJson("/api/v1/admin/certification/certificates/{$certificateId}")
            ->assertOk()
            ->assertJsonPath('data.certificate_number', CertificationCertificate::query()->value('certificate_number'));
    }

    public function test_restricted_account_cannot_list_certificates(): void
    {
        $stack = $this->readyStack();
        $this->passAssessment($stack);
        $stack['ambassador']->forceFill(['status' => AccountStatus::Restricted])->save();
        Sanctum::actingAs($stack['ambassador']);

        $this->getJson('/api/v1/certification/certificates')
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);
    }
}
