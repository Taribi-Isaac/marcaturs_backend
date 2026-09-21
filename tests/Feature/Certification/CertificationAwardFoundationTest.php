<?php

namespace Tests\Feature\Certification;

use App\Enums\AccountStatus;
use App\Enums\AdminStaffRole;
use App\Enums\CertificationAdminEventAction;
use App\Enums\CertificationAwardStatus;
use App\Enums\CertificationLessonContentType;
use App\Enums\CertificationLessonProgressStatus;
use App\Enums\CertificationProgrammeStatus;
use App\Enums\CertificationQuestionType;
use App\Models\CertificationAdminEvent;
use App\Models\CertificationAssessment;
use App\Models\CertificationAward;
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

class CertificationAwardFoundationTest extends TestCase
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
    private function readyStack(string $passMark = '50.00'): array
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

        $assessment = CertificationAssessment::factory()->for($version, 'programmeVersion')->create();

        $questions = [];
        $correctOptionIds = [];
        $wrongOptionIds = [];
        for ($i = 1; $i <= 2; $i++) {
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

    public function test_failed_attempt_does_not_create_award(): void
    {
        $stack = $this->readyStack();
        Sanctum::actingAs($stack['ambassador']);
        $base = "/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment/attempts";
        $attemptId = (int) $this->postJson($base)->assertCreated()->json('data.id');

        $this->postJson("{$base}/{$attemptId}/submit", [
            'answers' => $this->answersFor($stack['questions'], $stack['wrongOptionIds']),
        ])
            ->assertOk()
            ->assertJsonPath('data.passed', false);

        $this->assertSame(0, CertificationAward::query()->count());
    }

    public function test_passed_attempt_creates_immutable_award_once(): void
    {
        $stack = $this->readyStack();
        Sanctum::actingAs($stack['ambassador']);
        $base = "/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment/attempts";
        $attemptId = (int) $this->postJson($base)->assertCreated()->json('data.id');

        $this->postJson("{$base}/{$attemptId}/submit", [
            'answers' => $this->answersFor($stack['questions'], $stack['correctOptionIds']),
        ])
            ->assertOk()
            ->assertJsonPath('data.passed', true);

        $this->assertSame(1, CertificationAward::query()->count());
        $award = CertificationAward::query()->firstOrFail();
        $this->assertSame($stack['ambassador']->id, $award->user_id);
        $this->assertSame($stack['programme']->id, $award->programme_id);
        $this->assertSame($stack['version']->id, $award->programme_version_id);
        $this->assertSame($stack['enrollment']->id, $award->enrollment_id);
        $this->assertSame($attemptId, $award->assessment_attempt_id);
        $this->assertSame(CertificationAwardStatus::Awarded, $award->status);

        $this->postJson("{$base}/{$attemptId}/submit", [
            'answers' => $this->answersFor($stack['questions'], $stack['correctOptionIds']),
        ])->assertOk();
        $this->assertSame(1, CertificationAward::query()->count());

        $secondId = (int) $this->postJson($base)->assertCreated()->json('data.id');
        $this->postJson("{$base}/{$secondId}/submit", [
            'answers' => $this->answersFor($stack['questions'], $stack['correctOptionIds']),
        ])->assertOk()->assertJsonPath('data.passed', true);

        $this->assertSame(1, CertificationAward::query()->count());
        $this->assertSame($attemptId, CertificationAward::query()->value('assessment_attempt_id'));

        $this->assertTrue(
            CertificationAdminEvent::query()
                ->where('action', CertificationAdminEventAction::AwardCreated)
                ->exists(),
        );
    }

    public function test_award_version_isolation_after_newer_version_published(): void
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
        $this->postJson("/api/v1/admin/certification/programmes/{$stack['programme']->id}/versions", [
            'fee_amount_minor' => 2500000,
            'pass_mark_percent' => 90,
        ])->assertCreated();
        $this->postJson("/api/v1/admin/certification/programmes/{$stack['programme']->id}/versions/2/publish")->assertOk();

        $award = CertificationAward::query()->firstOrFail();
        $this->assertSame($stack['version']->id, $award->programme_version_id);
        $this->assertNotSame(
            $stack['programme']->fresh()->current_published_version_id,
            $award->programme_version_id,
        );
    }

    public function test_ambassador_can_list_and_show_own_award_only(): void
    {
        $stack = $this->readyStack();
        Sanctum::actingAs($stack['ambassador']);
        $base = "/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment/attempts";
        $attemptId = (int) $this->postJson($base)->assertCreated()->json('data.id');
        $this->postJson("{$base}/{$attemptId}/submit", [
            'answers' => $this->answersFor($stack['questions'], $stack['correctOptionIds']),
        ])->assertOk();

        $awardId = (int) CertificationAward::query()->value('id');

        $this->getJson('/api/v1/certification/awards')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $awardId)
            ->assertJsonPath('data.0.status', CertificationAwardStatus::Awarded->value)
            ->assertJsonPath('data.0.programme_version_id', $stack['version']->id);

        $this->getJson("/api/v1/certification/awards/{$awardId}")
            ->assertOk()
            ->assertJsonPath('data.assessment_attempt_id', $attemptId);

        $other = User::factory()->ambassador()->create();
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/certification/awards')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/certification/awards/{$awardId}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', ApiErrorCode::NOT_FOUND);

        Sanctum::actingAs(User::factory()->business()->create());
        $this->getJson('/api/v1/certification/awards')->assertStatus(403);
    }

    public function test_admin_can_inspect_awards(): void
    {
        $stack = $this->readyStack();
        Sanctum::actingAs($stack['ambassador']);
        $base = "/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment/attempts";
        $attemptId = (int) $this->postJson($base)->assertCreated()->json('data.id');
        $this->postJson("{$base}/{$attemptId}/submit", [
            'answers' => $this->answersFor($stack['questions'], $stack['correctOptionIds']),
        ])->assertOk();
        $awardId = (int) CertificationAward::query()->value('id');

        $admin = User::factory()->adminStaff(AdminStaffRole::Operations)->create();
        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/admin/certification/enrollments/{$stack['enrollment']->id}/awards")
            ->assertOk()
            ->assertJsonPath('data.0.id', $awardId)
            ->assertJsonPath('data.0.user.id', $stack['ambassador']->id);

        $this->getJson("/api/v1/admin/certification/awards/{$awardId}")
            ->assertOk()
            ->assertJsonPath('data.assessment_attempt.id', $attemptId);
    }

    public function test_restricted_account_cannot_list_awards(): void
    {
        $stack = $this->readyStack();
        Sanctum::actingAs($stack['ambassador']);
        $base = "/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment/attempts";
        $attemptId = (int) $this->postJson($base)->assertCreated()->json('data.id');
        $this->postJson("{$base}/{$attemptId}/submit", [
            'answers' => $this->answersFor($stack['questions'], $stack['correctOptionIds']),
        ])->assertOk();

        $stack['ambassador']->forceFill(['status' => AccountStatus::Restricted])->save();
        Sanctum::actingAs($stack['ambassador']);
        $this->getJson('/api/v1/certification/awards')
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);
    }
}
