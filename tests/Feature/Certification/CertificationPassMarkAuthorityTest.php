<?php

namespace Tests\Feature\Certification;

use App\Enums\AdminStaffRole;
use App\Enums\CertificationAssessmentAttemptStatus;
use App\Enums\CertificationLessonContentType;
use App\Enums\CertificationLessonProgressStatus;
use App\Enums\CertificationProgrammeStatus;
use App\Enums\CertificationProgrammeVersionStatus;
use App\Enums\CertificationQuestionType;
use App\Models\CertificationAssessment;
use App\Models\CertificationAssessmentAttempt;
use App\Models\CertificationEnrollment;
use App\Models\CertificationLesson;
use App\Models\CertificationLessonProgress;
use App\Models\CertificationModule;
use App\Models\CertificationProgramme;
use App\Models\CertificationProgrammeVersion;
use App\Models\CertificationQuestion;
use App\Models\CertificationQuestionOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CertificationPassMarkAuthorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_assessment_api_exposes_version_pass_mark_and_ignores_client_override(): void
    {
        $admin = User::factory()->adminStaff(AdminStaffRole::Operations)->create();
        $programme = CertificationProgramme::factory()->create([
            'status' => CertificationProgrammeStatus::Draft,
        ]);
        $version = CertificationProgrammeVersion::factory()->for($programme, 'programme')->create([
            'version_number' => 1,
            'status' => CertificationProgrammeVersionStatus::Draft,
            'pass_mark_percent' => '70.00',
        ]);
        $base = "/api/v1/admin/certification/programmes/{$programme->id}/versions/1";

        Sanctum::actingAs($admin);
        $this->postJson("{$base}/assessment", [
            'title' => 'NON-PRODUCTION Assessment',
            'pass_mark_percent' => 99,
        ])
            ->assertCreated()
            ->assertJsonPath('data.pass_mark_percent', '70.00');

        $this->assertFalse(
            Schema::hasColumn('certification_assessments', 'pass_mark_percent'),
        );

        $this->patchJson("{$base}/assessment", [
            'title' => 'NON-PRODUCTION Assessment Updated',
            'pass_mark_percent' => 10,
        ])
            ->assertOk()
            ->assertJsonPath('data.pass_mark_percent', '70.00');

        $this->assertSame('70.00', (string) $version->fresh()->pass_mark_percent);

        $this->patchJson($base, ['pass_mark_percent' => 85])
            ->assertOk()
            ->assertJsonPath('data.pass_mark_percent', '85.00');

        $this->getJson("{$base}/assessment")
            ->assertOk()
            ->assertJsonPath('data.pass_mark_percent', '85.00');
    }

    public function test_attempt_snapshots_version_pass_mark_and_scores_against_snapshot(): void
    {
        $ambassador = User::factory()->ambassador()->create();
        $programme = CertificationProgramme::factory()->create([
            'status' => CertificationProgrammeStatus::Published,
        ]);
        $version = CertificationProgrammeVersion::factory()->for($programme, 'programme')->published()->create([
            'version_number' => 1,
            'pass_mark_percent' => '50.00',
        ]);
        $programme->current_published_version_id = $version->id;
        $programme->save();

        $module = CertificationModule::factory()->for($version, 'version')->create(['sort_order' => 1]);
        $lesson = CertificationLesson::factory()->for($module, 'module')->create([
            'is_required' => true,
            'content_type' => CertificationLessonContentType::Text,
            'sort_order' => 1,
        ]);
        $assessment = CertificationAssessment::factory()->for($version, 'programmeVersion')->create();
        $question = CertificationQuestion::factory()->for($assessment, 'assessment')->create([
            'type' => CertificationQuestionType::SingleChoice,
            'sort_order' => 1,
        ]);
        $wrong = CertificationQuestionOption::factory()->for($question, 'question')->create([
            'is_correct' => false,
            'sort_order' => 1,
        ]);
        $correct = CertificationQuestionOption::factory()->for($question, 'question')->create([
            'is_correct' => true,
            'sort_order' => 2,
        ]);

        $enrollment = CertificationEnrollment::factory()->create([
            'user_id' => $ambassador->id,
            'programme_id' => $programme->id,
            'programme_version_id' => $version->id,
        ]);
        CertificationLessonProgress::factory()->create([
            'enrollment_id' => $enrollment->id,
            'lesson_id' => $lesson->id,
            'status' => CertificationLessonProgressStatus::Completed,
            'completed_at' => now(),
        ]);

        Sanctum::actingAs($ambassador);
        $attemptId = (int) $this->postJson("/api/v1/certification/enrollments/{$enrollment->id}/assessment/attempts")
            ->assertCreated()
            ->assertJsonPath('data.pass_mark_percent', '50.00')
            ->json('data.id');

        // Changing the draft-ineligible published version is blocked; mutate DB only to prove
        // submission uses the attempt snapshot, not live assessment/version re-read for scoring.
        CertificationAssessmentAttempt::query()->whereKey($attemptId)->update([
            'pass_mark_percent' => '50.00',
        ]);
        $version->forceFill(['pass_mark_percent' => '100.00'])->save();

        $this->postJson("/api/v1/certification/enrollments/{$enrollment->id}/assessment/attempts/{$attemptId}/submit", [
            'answers' => [
                ['question_id' => $question->id, 'selected_option_id' => $correct->id],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.pass_mark_percent', '50.00')
            ->assertJsonPath('data.status', CertificationAssessmentAttemptStatus::Submitted->value);

        unset($wrong);
    }

    public function test_learner_cannot_modify_pass_mark_via_submit_payload(): void
    {
        $ambassador = User::factory()->ambassador()->create();
        $programme = CertificationProgramme::factory()->create([
            'status' => CertificationProgrammeStatus::Published,
        ]);
        $version = CertificationProgrammeVersion::factory()->for($programme, 'programme')->published()->create([
            'version_number' => 1,
            'pass_mark_percent' => '100.00',
        ]);
        $programme->current_published_version_id = $version->id;
        $programme->save();

        $module = CertificationModule::factory()->for($version, 'version')->create(['sort_order' => 1]);
        $lesson = CertificationLesson::factory()->for($module, 'module')->create([
            'is_required' => true,
            'content_type' => CertificationLessonContentType::Text,
            'sort_order' => 1,
        ]);
        $assessment = CertificationAssessment::factory()->for($version, 'programmeVersion')->create();
        $question = CertificationQuestion::factory()->for($assessment, 'assessment')->create([
            'type' => CertificationQuestionType::SingleChoice,
            'sort_order' => 1,
        ]);
        $correct = CertificationQuestionOption::factory()->for($question, 'question')->create([
            'is_correct' => true,
            'sort_order' => 1,
        ]);
        CertificationQuestionOption::factory()->for($question, 'question')->create([
            'is_correct' => false,
            'sort_order' => 2,
        ]);

        $enrollment = CertificationEnrollment::factory()->create([
            'user_id' => $ambassador->id,
            'programme_id' => $programme->id,
            'programme_version_id' => $version->id,
        ]);
        CertificationLessonProgress::factory()->create([
            'enrollment_id' => $enrollment->id,
            'lesson_id' => $lesson->id,
            'status' => CertificationLessonProgressStatus::Completed,
            'completed_at' => now(),
        ]);

        Sanctum::actingAs($ambassador);
        $attemptId = (int) $this->postJson("/api/v1/certification/enrollments/{$enrollment->id}/assessment/attempts")
            ->assertCreated()
            ->json('data.id');

        $this->postJson("/api/v1/certification/enrollments/{$enrollment->id}/assessment/attempts/{$attemptId}/submit", [
            'pass_mark_percent' => 0,
            'passed' => true,
            'score_percent' => 100,
            'answers' => [
                ['question_id' => $question->id, 'selected_option_id' => $correct->id],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.pass_mark_percent', '100.00');
    }
}
