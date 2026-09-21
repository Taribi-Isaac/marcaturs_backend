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
use App\Jobs\Certification\GenerateCertificationCertificateArtifactJob;
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
use App\Services\Certification\CertificationCertificateArtifactService;
use App\Services\Certification\CertificationCertificatePdfRenderer;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CertificationCertificateArtifactTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{
     *     ambassador: User,
     *     programme: CertificationProgramme,
     *     version: CertificationProgrammeVersion,
     *     enrollment: CertificationEnrollment,
     *     questions: list<CertificationQuestion>,
     *     correctOptionIds: list<int>
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
        for ($i = 1; $i <= 2; $i++) {
            $question = CertificationQuestion::factory()->for($assessment, 'assessment')->create([
                'type' => CertificationQuestionType::SingleChoice,
                'sort_order' => $i,
            ]);
            CertificationQuestionOption::factory()->for($question, 'question')->create([
                'is_correct' => false,
                'sort_order' => 1,
            ]);
            $correct = CertificationQuestionOption::factory()->for($question, 'question')->create([
                'is_correct' => true,
                'sort_order' => 2,
            ]);
            $questions[] = $question;
            $correctOptionIds[] = $correct->id;
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

        return compact('ambassador', 'programme', 'version', 'enrollment', 'questions', 'correctOptionIds');
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

    private function passAssessment(array $stack): void
    {
        Sanctum::actingAs($stack['ambassador']);
        $base = "/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment/attempts";
        $attemptId = (int) $this->postJson($base)->assertCreated()->json('data.id');
        $this->postJson("{$base}/{$attemptId}/submit", [
            'answers' => $this->answersFor($stack['questions'], $stack['correctOptionIds']),
        ])->assertOk()->assertJsonPath('data.passed', true);
    }

    public function test_passing_generates_pdf_artifact_and_notifies_when_ready(): void
    {
        Storage::fake((string) config('certification.certificate_disk'));
        $stack = $this->readyStack();
        $this->passAssessment($stack);

        $certificate = CertificationCertificate::query()->firstOrFail();
        $this->assertSame(CertificationCertificateStatus::Issued, $certificate->status);
        $this->assertSame(CertificationCertificateArtifactStatus::Generated, $certificate->artifact_status);
        $this->assertNotNull($certificate->artifact_path);
        Storage::disk((string) $certificate->artifact_disk)->assertExists((string) $certificate->artifact_path);

        $pdf = Storage::disk((string) $certificate->artifact_disk)->get((string) $certificate->artifact_path);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString($certificate->certificate_number, $pdf);
        $this->assertStringContainsString('NON-PRODUCTION Certified Ambassador', $pdf);
        $this->assertStringContainsString('NON-PRODUCTION Ambassador Professional Certification', $pdf);

        $this->assertTrue(
            CertificationAdminEvent::query()
                ->where('action', CertificationAdminEventAction::CertificateArtifactGenerated)
                ->exists(),
        );

        $this->assertTrue(
            DatabaseNotification::query()
                ->where('notifiable_id', $stack['ambassador']->id)
                ->where('data->notification_type', NotificationType::CertificationCertificateAvailable->value)
                ->exists(),
        );
    }

    public function test_generation_failure_keeps_award_and_certificate_issued(): void
    {
        Storage::fake((string) config('certification.certificate_disk'));
        $stack = $this->readyStack();

        Queue::fake();
        $this->passAssessment($stack);

        $certificate = CertificationCertificate::query()->firstOrFail();
        $this->assertSame(CertificationCertificateArtifactStatus::PendingGeneration, $certificate->artifact_status);

        $this->mock(CertificationCertificatePdfRenderer::class, function ($mock): void {
            $mock->shouldReceive('render')->andThrow(new \RuntimeException('pdf boom'));
        });
        $this->app->forgetInstance(CertificationCertificateArtifactService::class);

        try {
            app(CertificationCertificateArtifactService::class)->generateForCertificateId($certificate->id);
            $this->fail('Expected generation to throw');
        } catch (\RuntimeException) {
            // expected
        }

        $certificate->refresh();
        $this->assertSame(1, CertificationAward::query()->count());
        $this->assertSame(CertificationCertificateStatus::Issued, $certificate->status);
        $this->assertSame(CertificationCertificateArtifactStatus::FailedRetryable, $certificate->artifact_status);
        $this->assertSame('generation_failed', $certificate->artifact_error_code);
        $this->assertSame($certificate->certificate_number, CertificationCertificate::query()->value('certificate_number'));

        Sanctum::actingAs($stack['ambassador']);
        $this->get("/api/v1/certification/certificates/{$certificate->id}/download")
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::CONFLICT);
    }

    public function test_owner_can_download_and_others_cannot(): void
    {
        Storage::fake((string) config('certification.certificate_disk'));
        $stack = $this->readyStack();
        $this->passAssessment($stack);
        $certificate = CertificationCertificate::query()->firstOrFail();

        Sanctum::actingAs($stack['ambassador']);
        $response = $this->get("/api/v1/certification/certificates/{$certificate->id}/download");
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF', $response->streamedContent());

        $this->assertTrue(
            CertificationAdminEvent::query()
                ->where('action', CertificationAdminEventAction::CertificateArtifactDownloaded)
                ->exists(),
        );

        Sanctum::actingAs(User::factory()->ambassador()->create());
        $this->get("/api/v1/certification/certificates/{$certificate->id}/download")->assertStatus(404);

        Sanctum::actingAs(User::factory()->business()->create());
        $this->get("/api/v1/certification/certificates/{$certificate->id}/download")->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $this->get("/api/v1/certification/certificates/{$certificate->id}/download")->assertStatus(401);

        $admin = User::factory()->adminStaff(AdminStaffRole::Operations)->create();
        Sanctum::actingAs($admin);
        $this->get("/api/v1/admin/certification/certificates/{$certificate->id}/download")
            ->assertOk();
    }

    public function test_retry_after_failure_preserves_certificate_identity(): void
    {
        Storage::fake((string) config('certification.certificate_disk'));
        $stack = $this->readyStack();
        Queue::fake();
        $this->passAssessment($stack);

        $certificate = CertificationCertificate::query()->firstOrFail();
        $number = $certificate->certificate_number;
        $issuedAt = $certificate->issued_at?->toIso8601String();

        $certificate->forceFill([
            'artifact_status' => CertificationCertificateArtifactStatus::FailedRetryable,
            'artifact_error_code' => 'generation_failed',
            'artifact_failed_at' => now(),
        ])->save();

        Queue::fake();
        $admin = User::factory()->adminStaff(AdminStaffRole::Operations)->create();
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/certification/certificates/{$certificate->id}/artifact/retry")
            ->assertOk()
            ->assertJsonPath('data.artifact_status', CertificationCertificateArtifactStatus::PendingGeneration->value)
            ->assertJsonPath('data.certificate_number', $number);

        Queue::assertPushed(GenerateCertificationCertificateArtifactJob::class);

        // Run generation successfully.
        app(CertificationCertificateArtifactService::class)->generateForCertificateId($certificate->id);
        $fresh = $certificate->fresh();
        $this->assertSame(CertificationCertificateArtifactStatus::Generated, $fresh->artifact_status);
        $this->assertSame($number, $fresh->certificate_number);
        $this->assertSame($issuedAt, $fresh->issued_at?->toIso8601String());
        $this->assertSame(1, CertificationCertificate::query()->count());
        $this->assertSame(1, CertificationAward::query()->count());
    }

    public function test_duplicate_generation_is_idempotent(): void
    {
        Storage::fake((string) config('certification.certificate_disk'));
        $stack = $this->readyStack();
        $this->passAssessment($stack);
        $certificate = CertificationCertificate::query()->firstOrFail();
        $path = $certificate->artifact_path;

        app(CertificationCertificateArtifactService::class)->generateForCertificateId($certificate->id);
        $fresh = $certificate->fresh();
        $this->assertSame($path, $fresh->artifact_path);
        $this->assertSame(1, CertificationCertificate::query()->count());
    }

    public function test_api_hides_storage_paths_and_restricted_blocks_download(): void
    {
        Storage::fake((string) config('certification.certificate_disk'));
        $stack = $this->readyStack();
        $this->passAssessment($stack);
        $certificate = CertificationCertificate::query()->firstOrFail();

        Sanctum::actingAs($stack['ambassador']);
        $this->getJson("/api/v1/certification/certificates/{$certificate->id}")
            ->assertOk()
            ->assertJsonPath('data.artifact_status', CertificationCertificateArtifactStatus::Generated->value)
            ->assertJsonPath('data.artifact_available', true)
            ->assertJsonMissingPath('data.artifact_disk')
            ->assertJsonMissingPath('data.artifact_path')
            ->assertJsonMissingPath('data.disk')
            ->assertJsonMissingPath('data.path');

        $stack['ambassador']->forceFill(['status' => AccountStatus::Restricted])->save();
        $this->get("/api/v1/certification/certificates/{$certificate->id}/download")
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);
    }
}
