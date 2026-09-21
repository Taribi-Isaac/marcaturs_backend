<?php

namespace Tests\Feature\Certification;

use App\Enums\AccountStatus;
use App\Enums\AdminStaffRole;
use App\Enums\CertificationAdminEventAction;
use App\Enums\CertificationAssessmentAttemptStatus;
use App\Enums\CertificationLessonContentType;
use App\Enums\CertificationLessonProgressStatus;
use App\Enums\CertificationProgrammeStatus;
use App\Enums\CertificationQuestionType;
use App\Models\CertificationAdminEvent;
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
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CertificationAssessmentAttemptTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{
     *     ambassador: User,
     *     programme: CertificationProgramme,
     *     version: CertificationProgrammeVersion,
     *     enrollment: CertificationEnrollment,
     *     assessment: CertificationAssessment,
     *     questions: list<CertificationQuestion>,
     *     correctOptionIds: list<int>,
     *     wrongOptionIds: list<int>
     * }
     */
    private function readyStack(int $questionCount = 2, string $passMark = '50.00', bool $completeRequired = true): array
    {
        $ambassador = User::factory()->ambassador()->create();
        $programme = CertificationProgramme::factory()->create([
            'status' => CertificationProgrammeStatus::Published,
        ]);
        $version = CertificationProgrammeVersion::factory()->for($programme, 'programme')->published()->create([
            'version_number' => 1,
            'pass_mark_percent' => $passMark,
        ]);
        $programme->current_published_version_id = $version->id;
        $programme->save();

        $module = CertificationModule::factory()->for($version, 'version')->create(['sort_order' => 1]);
        $required = CertificationLesson::factory()->for($module, 'module')->create([
            'is_required' => true,
            'content_type' => CertificationLessonContentType::Text,
            'sort_order' => 1,
        ]);

        $assessment = CertificationAssessment::factory()->for($version, 'programmeVersion')->create([
            'title' => 'NON-PRODUCTION Assessment',
        ]);

        $questions = [];
        $correctOptionIds = [];
        $wrongOptionIds = [];
        for ($i = 1; $i <= $questionCount; $i++) {
            $question = CertificationQuestion::factory()->for($assessment, 'assessment')->create([
                'prompt' => "NON-PRODUCTION Q{$i}",
                'type' => CertificationQuestionType::SingleChoice,
                'sort_order' => $i,
            ]);
            $wrong = CertificationQuestionOption::factory()->for($question, 'question')->create([
                'label' => 'Wrong',
                'is_correct' => false,
                'sort_order' => 1,
            ]);
            $correct = CertificationQuestionOption::factory()->for($question, 'question')->create([
                'label' => 'Right',
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

        if ($completeRequired) {
            CertificationLessonProgress::factory()->create([
                'enrollment_id' => $enrollment->id,
                'lesson_id' => $required->id,
                'status' => CertificationLessonProgressStatus::Completed,
            ]);
        }

        return compact(
            'ambassador',
            'programme',
            'version',
            'enrollment',
            'assessment',
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

    public function test_ineligible_learner_cannot_start_attempt(): void
    {
        $stack = $this->readyStack(completeRequired: false);
        Sanctum::actingAs($stack['ambassador']);

        $this->postJson("/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment/attempts")
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::CONFLICT);
    }

    public function test_start_is_idempotent_and_hides_answer_keys(): void
    {
        $stack = $this->readyStack();
        Sanctum::actingAs($stack['ambassador']);
        $base = "/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment/attempts";

        $first = $this->postJson($base)
            ->assertCreated()
            ->assertJsonPath('data.status', CertificationAssessmentAttemptStatus::InProgress->value)
            ->assertJsonPath('data.attempt_number', 1)
            ->assertJsonPath('data.pass_mark_percent', '50.00')
            ->assertJsonMissingPath('data.assessment.questions.0.options.0.is_correct');

        $attemptId = (int) $first->json('data.id');

        $this->postJson($base)
            ->assertOk()
            ->assertJsonPath('data.id', $attemptId)
            ->assertJsonPath('data.attempt_number', 1);

        $this->assertSame(1, CertificationAssessmentAttempt::query()->count());
    }

    public function test_scoring_pass_fail_boundaries_and_retake(): void
    {
        $stack = $this->readyStack(questionCount: 2, passMark: '50.00');
        Sanctum::actingAs($stack['ambassador']);
        $base = "/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment/attempts";

        $attemptId = (int) $this->postJson($base)->assertCreated()->json('data.id');

        $failAnswers = [
            [
                'question_id' => $stack['questions'][0]->id,
                'selected_option_id' => $stack['wrongOptionIds'][0],
            ],
            [
                'question_id' => $stack['questions'][1]->id,
                'selected_option_id' => $stack['wrongOptionIds'][1],
            ],
        ];

        $this->postJson("{$base}/{$attemptId}/submit", ['answers' => $failAnswers])
            ->assertOk()
            ->assertJsonPath('data.status', CertificationAssessmentAttemptStatus::Submitted->value)
            ->assertJsonPath('data.correct_count', 0)
            ->assertJsonPath('data.total_questions', 2)
            ->assertJsonPath('data.score_percent', '0.00')
            ->assertJsonPath('data.passed', false)
            ->assertJsonMissingPath('data.answers.0.is_correct');

        $this->postJson("{$base}/{$attemptId}/submit", ['answers' => $failAnswers])
            ->assertOk()
            ->assertJsonPath('data.score_percent', '0.00')
            ->assertJsonPath('data.passed', false);

        $this->assertSame(1, CertificationAssessmentAttempt::query()->count());

        $secondId = (int) $this->postJson($base)->assertCreated()->json('data.id');
        $this->assertNotSame($attemptId, $secondId);
        $this->assertSame(2, (int) CertificationAssessmentAttempt::query()->whereKey($secondId)->value('attempt_number'));

        $boundaryAnswers = [
            [
                'question_id' => $stack['questions'][0]->id,
                'selected_option_id' => $stack['correctOptionIds'][0],
            ],
            [
                'question_id' => $stack['questions'][1]->id,
                'selected_option_id' => $stack['wrongOptionIds'][1],
            ],
        ];

        $this->postJson("{$base}/{$secondId}/submit", ['answers' => $boundaryAnswers])
            ->assertOk()
            ->assertJsonPath('data.correct_count', 1)
            ->assertJsonPath('data.score_percent', '50.00')
            ->assertJsonPath('data.passed', true);

        $this->assertTrue(
            CertificationAdminEvent::query()
                ->where('action', CertificationAdminEventAction::AssessmentAttemptPassed)
                ->exists(),
        );

        $first = CertificationAssessmentAttempt::query()->findOrFail($attemptId);
        $this->assertFalse((bool) $first->passed);
        $this->assertSame('0.00', (string) $first->score_percent);
    }

    public function test_rejects_cross_question_option_and_foreign_question(): void
    {
        $stack = $this->readyStack(questionCount: 2);
        Sanctum::actingAs($stack['ambassador']);
        $base = "/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment/attempts";
        $attemptId = (int) $this->postJson($base)->assertCreated()->json('data.id');

        $this->postJson("{$base}/{$attemptId}/submit", [
            'answers' => [
                [
                    'question_id' => $stack['questions'][0]->id,
                    'selected_option_id' => $stack['correctOptionIds'][1],
                ],
                [
                    'question_id' => $stack['questions'][1]->id,
                    'selected_option_id' => $stack['correctOptionIds'][1],
                ],
            ],
        ])->assertStatus(400);

        $other = $this->readyStack(questionCount: 1);
        $this->postJson("{$base}/{$attemptId}/submit", [
            'answers' => [
                [
                    'question_id' => $other['questions'][0]->id,
                    'selected_option_id' => $other['correctOptionIds'][0],
                ],
                [
                    'question_id' => $stack['questions'][1]->id,
                    'selected_option_id' => $stack['correctOptionIds'][1],
                ],
            ],
        ])->assertStatus(400);
    }

    public function test_idor_and_role_restrictions(): void
    {
        $stack = $this->readyStack();
        Sanctum::actingAs($stack['ambassador']);
        $attemptId = (int) $this->postJson("/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment/attempts")
            ->assertCreated()
            ->json('data.id');

        $other = User::factory()->ambassador()->create();
        Sanctum::actingAs($other);
        $this->getJson("/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment/attempts/{$attemptId}")
            ->assertStatus(404);
        $this->postJson("/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment/attempts/{$attemptId}/submit", [
            'answers' => $this->answersFor($stack['questions'], $stack['correctOptionIds']),
        ])->assertStatus(404);

        Sanctum::actingAs(User::factory()->business()->create());
        $this->postJson("/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment/attempts")
            ->assertStatus(403);
    }

    public function test_version_isolation_for_attempts(): void
    {
        $stack = $this->readyStack();
        Sanctum::actingAs($stack['ambassador']);
        $base = "/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment/attempts";
        $attemptId = (int) $this->postJson($base)->assertCreated()->json('data.id');
        $this->postJson("{$base}/{$attemptId}/submit", [
            'answers' => $this->answersFor($stack['questions'], $stack['correctOptionIds']),
        ])->assertOk()->assertJsonPath('data.programme_version_id', $stack['version']->id);

        $admin = User::factory()->adminStaff(AdminStaffRole::Operations)->create();
        Sanctum::actingAs($admin);
        $v2 = $this->postJson("/api/v1/admin/certification/programmes/{$stack['programme']->id}/versions", [
            'fee_amount_minor' => 2500000,
            'pass_mark_percent' => 90,
        ])->assertCreated()->json('data');
        $v2Base = "/api/v1/admin/certification/programmes/{$stack['programme']->id}/versions/{$v2['version_number']}";
        $this->postJson("{$v2Base}/assessment", [
            'title' => 'NON-PRODUCTION V2 Assessment',
            'pass_mark_percent' => 90,
        ])->assertCreated();
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
        $history = $this->getJson($base)->assertOk()->json('data');
        $this->assertSame($stack['version']->id, $history[0]['programme_version_id']);
        $this->assertSame($stack['assessment']->id, $history[0]['assessment_id']);
    }

    public function test_admin_can_inspect_attempts_with_correctness(): void
    {
        $stack = $this->readyStack();
        Sanctum::actingAs($stack['ambassador']);
        $base = "/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment/attempts";
        $attemptId = (int) $this->postJson($base)->assertCreated()->json('data.id');
        $this->postJson("{$base}/{$attemptId}/submit", [
            'answers' => $this->answersFor($stack['questions'], $stack['correctOptionIds']),
        ])->assertOk();

        $admin = User::factory()->adminStaff(AdminStaffRole::Operations)->create();
        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/admin/certification/enrollments/{$stack['enrollment']->id}/assessment/attempts")
            ->assertOk()
            ->assertJsonPath('data.0.id', $attemptId);

        $this->getJson("/api/v1/admin/certification/enrollments/{$stack['enrollment']->id}/assessment/attempts/{$attemptId}")
            ->assertOk()
            ->assertJsonPath('data.answers.0.is_correct', true);
    }

    public function test_restricted_account_blocked_from_attempts(): void
    {
        $stack = $this->readyStack();
        $stack['ambassador']->forceFill(['status' => AccountStatus::Restricted])->save();
        Sanctum::actingAs($stack['ambassador']);

        $this->postJson("/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment/attempts")
            ->assertStatus(403);
    }

    public function test_exact_pass_mark_boundary_below_fails(): void
    {
        $stack = $this->readyStack(questionCount: 3, passMark: '66.67');
        Sanctum::actingAs($stack['ambassador']);
        $base = "/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment/attempts";
        $attemptId = (int) $this->postJson($base)->assertCreated()->json('data.id');

        // 2/3 = 66.67 rounded half-up → passes at pass_mark 66.67
        $answers = [
            ['question_id' => $stack['questions'][0]->id, 'selected_option_id' => $stack['correctOptionIds'][0]],
            ['question_id' => $stack['questions'][1]->id, 'selected_option_id' => $stack['correctOptionIds'][1]],
            ['question_id' => $stack['questions'][2]->id, 'selected_option_id' => $stack['wrongOptionIds'][2]],
        ];
        $this->postJson("{$base}/{$attemptId}/submit", ['answers' => $answers])
            ->assertOk()
            ->assertJsonPath('data.score_percent', '66.67')
            ->assertJsonPath('data.passed', true);

        $secondId = (int) $this->postJson($base)->assertCreated()->json('data.id');
        $failAnswers = [
            ['question_id' => $stack['questions'][0]->id, 'selected_option_id' => $stack['correctOptionIds'][0]],
            ['question_id' => $stack['questions'][1]->id, 'selected_option_id' => $stack['wrongOptionIds'][1]],
            ['question_id' => $stack['questions'][2]->id, 'selected_option_id' => $stack['wrongOptionIds'][2]],
        ];
        // 1/3 = 33.33
        $this->postJson("{$base}/{$secondId}/submit", ['answers' => $failAnswers])
            ->assertOk()
            ->assertJsonPath('data.score_percent', '33.33')
            ->assertJsonPath('data.passed', false);
    }
}
