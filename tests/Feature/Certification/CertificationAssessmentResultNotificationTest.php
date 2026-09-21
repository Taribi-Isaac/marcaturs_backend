<?php

namespace Tests\Feature\Certification;

use App\Enums\AccountStatus;
use App\Enums\CertificationAssessmentAttemptStatus;
use App\Enums\CertificationLessonContentType;
use App\Enums\CertificationLessonProgressStatus;
use App\Enums\CertificationProgrammeStatus;
use App\Enums\CertificationQuestionType;
use App\Enums\NotificationType;
use App\Models\CertificationAssessment;
use App\Models\CertificationAssessmentAttempt;
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
use App\Notifications\CertificationAssessmentResultNotification;
use App\Services\Notifications\CertificationAssessmentResultNotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CertificationAssessmentResultNotificationTest extends TestCase
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
    private function readyStack(int $questionCount = 2, string $passMark = '50.00'): array
    {
        $ambassador = User::factory()->ambassador()->create([
            'name' => 'NON-PRODUCTION Assessment Result Learner',
        ]);
        $programme = CertificationProgramme::factory()->create([
            'status' => CertificationProgrammeStatus::Published,
            'name' => 'NON-PRODUCTION Result Programme',
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
            'title' => 'NON-PRODUCTION Final Assessment',
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

    public function test_failing_submit_creates_result_notification_mail_and_in_app(): void
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

        $notification = DatabaseNotification::query()
            ->where('notifiable_id', $stack['ambassador']->id)
            ->where('data->notification_type', NotificationType::CertificationAssessmentResult->value)
            ->first();

        $this->assertNotNull($notification);
        $data = $notification->data;
        $this->assertSame($attemptId, (int) $data['attempt_id']);
        $this->assertSame($stack['enrollment']->id, (int) $data['enrollment_id']);
        $this->assertFalse((bool) $data['passed']);
        $this->assertSame('failed', $data['result']);
        $this->assertSame('0.00', (string) $data['score_percent']);
        $this->assertSame('50.00', (string) $data['pass_mark_percent']);
        $this->assertSame('NON-PRODUCTION Result Programme', $data['programme_name']);
        $this->assertArrayNotHasKey('answers', $data);
        $this->assertArrayNotHasKey('is_correct', $data);
        $this->assertSame(0, CertificationAward::query()->count());

        $mail = (new CertificationAssessmentResultNotification($attemptId, $stack['ambassador']->id))
            ->toMail($stack['ambassador']);
        $this->assertNotNull($mail);
        $this->assertStringContainsString('not passed', strtolower((string) $mail->subject));
    }

    public function test_passing_submit_creates_result_notification_and_does_not_replace_award_flow(): void
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

        $notification = DatabaseNotification::query()
            ->where('notifiable_id', $stack['ambassador']->id)
            ->where('data->notification_type', NotificationType::CertificationAssessmentResult->value)
            ->first();

        $this->assertNotNull($notification);
        $this->assertTrue((bool) $notification->data['passed']);
        $this->assertSame('passed', $notification->data['result']);
        $this->assertSame('100.00', (string) $notification->data['score_percent']);
    }

    public function test_resubmit_does_not_duplicate_result_notification(): void
    {
        $stack = $this->readyStack();
        Sanctum::actingAs($stack['ambassador']);
        $base = "/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment/attempts";
        $attemptId = (int) $this->postJson($base)->assertCreated()->json('data.id');
        $answers = $this->answersFor($stack['questions'], $stack['wrongOptionIds']);

        $this->postJson("{$base}/{$attemptId}/submit", ['answers' => $answers])->assertOk();
        $this->postJson("{$base}/{$attemptId}/submit", ['answers' => $answers])->assertOk();

        $this->assertSame(
            1,
            DatabaseNotification::query()
                ->where('notifiable_id', $stack['ambassador']->id)
                ->where('data->notification_type', NotificationType::CertificationAssessmentResult->value)
                ->count(),
        );
    }

    public function test_retakes_each_produce_a_result_notification_tied_to_attempt(): void
    {
        $stack = $this->readyStack();
        Sanctum::actingAs($stack['ambassador']);
        $base = "/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment/attempts";

        $firstId = (int) $this->postJson($base)->assertCreated()->json('data.id');
        $this->postJson("{$base}/{$firstId}/submit", [
            'answers' => $this->answersFor($stack['questions'], $stack['wrongOptionIds']),
        ])->assertOk();

        $secondId = (int) $this->postJson($base)->assertCreated()->json('data.id');
        $this->postJson("{$base}/{$secondId}/submit", [
            'answers' => $this->answersFor($stack['questions'], $stack['correctOptionIds']),
        ])->assertOk();

        $rows = DatabaseNotification::query()
            ->where('notifiable_id', $stack['ambassador']->id)
            ->where('data->notification_type', NotificationType::CertificationAssessmentResult->value)
            ->get();

        $this->assertCount(2, $rows);
        $attemptIds = $rows->pluck('data.attempt_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->assertSame([$firstId, $secondId], $attemptIds);
        $this->assertSame(1, CertificationAward::query()->count());
    }

    public function test_duplicate_dispatcher_calls_are_idempotent(): void
    {
        $stack = $this->readyStack();
        Sanctum::actingAs($stack['ambassador']);
        $base = "/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment/attempts";
        $attemptId = (int) $this->postJson($base)->assertCreated()->json('data.id');
        $this->postJson("{$base}/{$attemptId}/submit", [
            'answers' => $this->answersFor($stack['questions'], $stack['wrongOptionIds']),
        ])->assertOk();

        $attempt = CertificationAssessmentAttempt::query()->findOrFail($attemptId);
        app(CertificationAssessmentResultNotificationDispatcher::class)->notifyResult($attempt);
        app(CertificationAssessmentResultNotificationDispatcher::class)->notifyResult($attempt);

        $this->assertSame(
            1,
            DatabaseNotification::query()
                ->where('notifiable_id', $stack['ambassador']->id)
                ->where('data->notification_type', NotificationType::CertificationAssessmentResult->value)
                ->count(),
        );
    }

    public function test_failed_validation_does_not_create_result_notification(): void
    {
        $stack = $this->readyStack();
        Sanctum::actingAs($stack['ambassador']);
        $base = "/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment/attempts";
        $attemptId = (int) $this->postJson($base)->assertCreated()->json('data.id');

        $this->postJson("{$base}/{$attemptId}/submit", [
            'answers' => [
                [
                    'question_id' => $stack['questions'][0]->id,
                    'selected_option_id' => $stack['correctOptionIds'][0],
                ],
            ],
        ])->assertStatus(400);

        $this->assertSame(
            CertificationAssessmentAttemptStatus::InProgress,
            CertificationAssessmentAttempt::query()->findOrFail($attemptId)->status,
        );
        $this->assertSame(
            0,
            DatabaseNotification::query()
                ->where('data->notification_type', NotificationType::CertificationAssessmentResult->value)
                ->count(),
        );
    }

    public function test_other_users_do_not_receive_result_notification(): void
    {
        $stack = $this->readyStack();
        $other = User::factory()->ambassador()->create();
        Sanctum::actingAs($stack['ambassador']);
        $base = "/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment/attempts";
        $attemptId = (int) $this->postJson($base)->assertCreated()->json('data.id');
        $this->postJson("{$base}/{$attemptId}/submit", [
            'answers' => $this->answersFor($stack['questions'], $stack['correctOptionIds']),
        ])->assertOk();

        $this->assertSame(
            0,
            DatabaseNotification::query()
                ->where('notifiable_id', $other->id)
                ->where('data->notification_type', NotificationType::CertificationAssessmentResult->value)
                ->count(),
        );
        $this->assertSame(
            1,
            DatabaseNotification::query()
                ->where('notifiable_id', $stack['ambassador']->id)
                ->where('data->notification_type', NotificationType::CertificationAssessmentResult->value)
                ->count(),
        );
    }

    public function test_suspended_recipient_still_receives_transactional_result_notification(): void
    {
        $stack = $this->readyStack();
        Sanctum::actingAs($stack['ambassador']);
        $base = "/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment/attempts";
        $attemptId = (int) $this->postJson($base)->assertCreated()->json('data.id');
        $this->postJson("{$base}/{$attemptId}/submit", [
            'answers' => $this->answersFor($stack['questions'], $stack['wrongOptionIds']),
        ])->assertOk();

        DatabaseNotification::query()
            ->where('notifiable_id', $stack['ambassador']->id)
            ->where('data->notification_type', NotificationType::CertificationAssessmentResult->value)
            ->delete();

        $stack['ambassador']->forceFill(['status' => AccountStatus::Suspended])->save();
        $attempt = CertificationAssessmentAttempt::query()->findOrFail($attemptId);

        Notification::fake();
        app(CertificationAssessmentResultNotificationDispatcher::class)->notifyResult($attempt);

        Notification::assertSentTo(
            $stack['ambassador']->fresh(),
            CertificationAssessmentResultNotification::class,
        );
    }

    public function test_notification_channels_are_database_and_mail(): void
    {
        $stack = $this->readyStack();
        Sanctum::actingAs($stack['ambassador']);
        $base = "/api/v1/certification/enrollments/{$stack['enrollment']->id}/assessment/attempts";
        $attemptId = (int) $this->postJson($base)->assertCreated()->json('data.id');
        $this->postJson("{$base}/{$attemptId}/submit", [
            'answers' => $this->answersFor($stack['questions'], $stack['correctOptionIds']),
        ])->assertOk();

        $notification = new CertificationAssessmentResultNotification($attemptId, $stack['ambassador']->id);
        $channels = $notification->via($stack['ambassador']);
        $this->assertContains('database', $channels);
        $this->assertContains('mail', $channels);
        $this->assertNotNull($notification->toMail($stack['ambassador']));
    }
}
