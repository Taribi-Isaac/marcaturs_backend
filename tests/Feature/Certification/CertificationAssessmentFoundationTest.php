<?php

namespace Tests\Feature\Certification;

use App\Enums\AccountStatus;
use App\Enums\AdminStaffRole;
use App\Enums\CertificationAdminEventAction;
use App\Enums\CertificationLessonContentType;
use App\Enums\CertificationLessonProgressStatus;
use App\Enums\CertificationProgrammeStatus;
use App\Enums\CertificationProgrammeVersionStatus;
use App\Enums\CertificationQuestionType;
use App\Models\CertificationAssessment;
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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CertificationAssessmentFoundationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{
     *     admin: User,
     *     programme: CertificationProgramme,
     *     version: CertificationProgrammeVersion,
     *     base: string
     * }
     */
    private function draftVersionStack(): array
    {
        $admin = User::factory()->adminStaff(AdminStaffRole::Operations)->create();
        $programme = CertificationProgramme::factory()->create([
            'status' => CertificationProgrammeStatus::Draft,
        ]);
        $version = CertificationProgrammeVersion::factory()->for($programme, 'programme')->publishable()->create([
            'version_number' => 1,
            'status' => CertificationProgrammeVersionStatus::Draft,
            'pass_mark_percent' => '75.00',
        ]);

        return [
            'admin' => $admin,
            'programme' => $programme,
            'version' => $version,
            'base' => "/api/v1/admin/certification/programmes/{$programme->id}/versions/{$version->version_number}",
        ];
    }

    /**
     * @return array{
     *     ambassador: User,
     *     programme: CertificationProgramme,
     *     version: CertificationProgrammeVersion,
     *     enrollment: CertificationEnrollment,
     *     assessment: CertificationAssessment,
     *     required: CertificationLesson,
     *     question: CertificationQuestion
     * }
     */
    private function enrolledEligibleStack(bool $completeRequired = true): array
    {
        $ambassador = User::factory()->ambassador()->create();
        $programme = CertificationProgramme::factory()->create([
            'status' => CertificationProgrammeStatus::Published,
        ]);
        $version = CertificationProgrammeVersion::factory()->for($programme, 'programme')->published()->create([
            'version_number' => 1,
            'pass_mark_percent' => '80.00',
        ]);
        $programme->current_published_version_id = $version->id;
        $programme->save();

        $module = CertificationModule::factory()->for($version, 'version')->create(['sort_order' => 1]);
        $required = CertificationLesson::factory()->for($module, 'module')->create([
            'title' => 'NON-PRODUCTION Required',
            'content_type' => CertificationLessonContentType::Text,
            'is_required' => true,
            'sort_order' => 1,
        ]);

        $assessment = CertificationAssessment::factory()->for($version, 'programmeVersion')->create([
            'title' => 'NON-PRODUCTION Assessment',
        ]);
        $question = CertificationQuestion::factory()->for($assessment, 'assessment')->create([
            'prompt' => 'NON-PRODUCTION Q1',
            'type' => CertificationQuestionType::SingleChoice,
            'sort_order' => 1,
        ]);
        CertificationQuestionOption::factory()->for($question, 'question')->create([
            'label' => 'Wrong',
            'is_correct' => false,
            'sort_order' => 1,
        ]);
        CertificationQuestionOption::factory()->for($question, 'question')->create([
            'label' => 'Right',
            'is_correct' => true,
            'sort_order' => 2,
        ]);

        $enrollment = CertificationEnrollment::factory()->create([
            'user_id' => $ambassador->id,
            'programme_id' => $programme->id,
            'programme_version_id' => $version->id,
        ]);

        if ($completeRequired) {
            CertificationLessonProgress::factory()->create([
                'enrollment_id' => $enrollment->id,
                'lesson_id' => $required->id,
                'status' => CertificationLessonProgressStatus::Completed,
                'started_at' => now(),
                'completed_at' => now(),
            ]);
        }

        return compact('ambassador', 'programme', 'version', 'enrollment', 'assessment', 'required', 'question');
    }

    public function test_admin_can_manage_draft_assessment_and_single_choice_questions(): void
    {
        $stack = $this->draftVersionStack();
        Sanctum::actingAs($stack['admin']);
        $base = $stack['base'];

        $this->postJson("{$base}/assessment", [
            'title' => 'NON-PRODUCTION Final Assessment',
            'instructions' => 'Answer carefully.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'NON-PRODUCTION Final Assessment')
            ->assertJsonPath('data.pass_mark_percent', '75.00')
            ->assertJsonPath('data.question_count', 0);

        $this->postJson("{$base}/assessment", ['title' => 'Duplicate'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::CONFLICT);

        $this->patchJson("{$base}/assessment", [
            'pass_mark_percent' => 85,
            'title' => 'NON-PRODUCTION Final Assessment v1',
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'NON-PRODUCTION Final Assessment v1')
            // Assessment payloads cannot change the version pass mark (MH-BE-048).
            ->assertJsonPath('data.pass_mark_percent', '75.00');

        $this->assertSame('75.00', (string) $stack['version']->fresh()->pass_mark_percent);

        $created = $this->postJson("{$base}/assessment/questions", [
            'prompt' => 'NON-PRODUCTION Which is correct?',
            'type' => CertificationQuestionType::SingleChoice->value,
            'options' => [
                ['label' => 'A', 'is_correct' => false],
                ['label' => 'B', 'is_correct' => true],
                ['label' => 'C', 'is_correct' => false],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', CertificationQuestionType::SingleChoice->value)
            ->assertJsonPath('data.options.1.is_correct', true);

        $q1 = (int) $created->json('data.id');

        $q2 = (int) $this->postJson("{$base}/assessment/questions", [
            'prompt' => 'NON-PRODUCTION Second question?',
            'type' => CertificationQuestionType::SingleChoice->value,
            'options' => [
                ['label' => 'Yes', 'is_correct' => true],
                ['label' => 'No', 'is_correct' => false],
            ],
        ])->assertCreated()->json('data.id');

        $this->postJson("{$base}/assessment/questions/reorder", [
            'question_ids' => [$q2, $q1],
        ])
            ->assertOk()
            ->assertJsonPath('data.0.id', $q2)
            ->assertJsonPath('data.0.sort_order', 1)
            ->assertJsonPath('data.1.id', $q1)
            ->assertJsonPath('data.1.sort_order', 2);

        $this->getJson("{$base}/assessment")
            ->assertOk()
            ->assertJsonPath('data.questions.0.options.0.is_correct', true);

        $this->assertDatabaseHas('certification_admin_events', [
            'action' => CertificationAdminEventAction::AssessmentCreated->value,
            'programme_version_id' => $stack['version']->id,
        ]);
    }

    public function test_question_validation_requires_exactly_one_correct_option(): void
    {
        $stack = $this->draftVersionStack();
        Sanctum::actingAs($stack['admin']);
        $this->postJson("{$stack['base']}/assessment", ['title' => 'NON-PRODUCTION Assessment'])->assertCreated();

        $this->postJson("{$stack['base']}/assessment/questions", [
            'prompt' => 'Bad',
            'type' => CertificationQuestionType::SingleChoice->value,
            'options' => [
                ['label' => 'A', 'is_correct' => true],
                ['label' => 'B', 'is_correct' => true],
            ],
        ])->assertStatus(400);

        $this->postJson("{$stack['base']}/assessment/questions", [
            'prompt' => 'Bad type',
            'type' => 'essay',
            'options' => [
                ['label' => 'A', 'is_correct' => true],
                ['label' => 'B', 'is_correct' => false],
            ],
        ])->assertStatus(400);
    }

    public function test_published_version_assessment_is_immutable(): void
    {
        $stack = $this->draftVersionStack();
        Sanctum::actingAs($stack['admin']);
        $this->postJson("{$stack['base']}/assessment", ['title' => 'NON-PRODUCTION Assessment'])->assertCreated();
        $this->postJson("{$stack['base']}/assessment/questions", [
            'prompt' => 'Q',
            'type' => CertificationQuestionType::SingleChoice->value,
            'options' => [
                ['label' => 'A', 'is_correct' => true],
                ['label' => 'B', 'is_correct' => false],
            ],
        ])->assertCreated();

        $this->postJson("{$stack['base']}/publish")->assertOk();

        $this->patchJson("{$stack['base']}/assessment", ['title' => 'Changed'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::CONFLICT);

        $this->postJson("{$stack['base']}/assessment/questions", [
            'prompt' => 'Another',
            'type' => CertificationQuestionType::SingleChoice->value,
            'options' => [
                ['label' => 'A', 'is_correct' => true],
                ['label' => 'B', 'is_correct' => false],
            ],
        ])->assertStatus(409);
    }

    public function test_unauthorized_roles_cannot_manage_assessment(): void
    {
        $stack = $this->draftVersionStack();

        $this->getJson("{$stack['base']}/assessment")->assertStatus(401);

        Sanctum::actingAs(User::factory()->ambassador()->create());
        $this->postJson("{$stack['base']}/assessment", ['title' => 'Nope'])->assertStatus(403);

        Sanctum::actingAs(User::factory()->adminStaff(AdminStaffRole::Verification)->create());
        $this->getJson("{$stack['base']}/assessment")->assertStatus(403);
    }

    public function test_learner_assessment_requires_eligibility_and_hides_answer_keys(): void
    {
        $stack = $this->enrolledEligibleStack(completeRequired: false);
        Sanctum::actingAs($stack['ambassador']);

        $locked = $this->getJson("/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment")
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.assessment_eligibility.eligible', false)
            ->assertJsonPath('data.assessment.id', $stack['assessment']->id)
            ->assertJsonPath('data.assessment.question_count', 1)
            ->assertJsonMissingPath('data.assessment.questions');

        $this->assertArrayNotHasKey('is_correct', $locked->json('data.assessment') ?? []);

        CertificationLessonProgress::factory()->create([
            'enrollment_id' => $stack['enrollment']->id,
            'lesson_id' => $stack['required']->id,
            'status' => CertificationLessonProgressStatus::Completed,
        ]);

        $open = $this->getJson("/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment")
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.assessment.questions.0.prompt', 'NON-PRODUCTION Q1')
            ->assertJsonPath('data.assessment.questions.0.options.0.label', 'Wrong')
            ->assertJsonMissingPath('data.assessment.questions.0.options.0.is_correct')
            ->assertJsonMissingPath('data.assessment.questions.0.options.1.is_correct');

        $this->assertSame(2, count($open->json('data.assessment.questions.0.options')));
    }

    public function test_learner_idor_and_version_isolation(): void
    {
        $stack = $this->enrolledEligibleStack();
        $other = User::factory()->ambassador()->create();
        Sanctum::actingAs($other);
        $this->getJson("/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment")
            ->assertStatus(404);

        Sanctum::actingAs(User::factory()->business()->create());
        $this->getJson("/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment")
            ->assertStatus(403);

        $admin = User::factory()->adminStaff(AdminStaffRole::Operations)->create();
        Sanctum::actingAs($admin);
        $v2 = $this->postJson("/api/v1/admin/certification/programmes/{$stack['programme']->id}/versions", [
            'fee_amount_minor' => 2500000,
            'pass_mark_percent' => 90,
        ])->assertCreated()->json('data');
        $v2Base = "/api/v1/admin/certification/programmes/{$stack['programme']->id}/versions/{$v2['version_number']}";
        $this->postJson("{$v2Base}/assessment", ['title' => 'NON-PRODUCTION V2 Assessment'])->assertCreated();
        $this->postJson("{$v2Base}/assessment/questions", [
            'prompt' => 'NON-PRODUCTION V2 Q',
            'type' => CertificationQuestionType::SingleChoice->value,
            'options' => [
                ['label' => 'A', 'is_correct' => true],
                ['label' => 'B', 'is_correct' => false],
            ],
        ])->assertCreated();
        $this->postJson("{$v2Base}/publish")->assertOk();

        Sanctum::actingAs($stack['ambassador']);
        $this->getJson("/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment")
            ->assertOk()
            ->assertJsonPath('data.programme_version.id', $stack['version']->id)
            ->assertJsonPath('data.assessment.id', $stack['assessment']->id)
            ->assertJsonPath('data.assessment.title', 'NON-PRODUCTION Assessment');
    }

    public function test_restricted_account_cannot_access_learner_assessment(): void
    {
        $stack = $this->enrolledEligibleStack();
        $stack['ambassador']->forceFill(['status' => AccountStatus::Restricted])->save();
        Sanctum::actingAs($stack['ambassador']);

        $this->getJson("/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment")
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);
    }

    public function test_cross_version_question_id_is_not_found(): void
    {
        $stack = $this->draftVersionStack();
        Sanctum::actingAs($stack['admin']);
        $this->postJson("{$stack['base']}/assessment", ['title' => 'A'])->assertCreated();
        $q1 = (int) $this->postJson("{$stack['base']}/assessment/questions", [
            'prompt' => 'Q1',
            'type' => CertificationQuestionType::SingleChoice->value,
            'options' => [
                ['label' => 'A', 'is_correct' => true],
                ['label' => 'B', 'is_correct' => false],
            ],
        ])->assertCreated()->json('data.id');

        $this->postJson("{$stack['base']}/publish")->assertOk();

        $v2 = $this->postJson("/api/v1/admin/certification/programmes/{$stack['programme']->id}/versions", [
            'fee_amount_minor' => 1500000,
            'pass_mark_percent' => 70,
        ])->assertCreated()->json('data');
        $v2Base = "/api/v1/admin/certification/programmes/{$stack['programme']->id}/versions/{$v2['version_number']}";
        $this->postJson("{$v2Base}/assessment", ['title' => 'B'])->assertCreated();

        $this->getJson("{$v2Base}/assessment/questions/{$q1}")
            ->assertStatus(404);
    }
}
