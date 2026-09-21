<?php

namespace App\Services\Certification;

use App\Enums\AdminPermission;
use App\Enums\CertificationAdminEventAction;
use App\Enums\CertificationAssessmentAttemptStatus;
use App\Models\CertificationAdminEvent;
use App\Models\CertificationAssessment;
use App\Models\CertificationAssessmentAttempt;
use App\Models\CertificationAttemptAnswer;
use App\Models\CertificationEnrollment;
use App\Models\CertificationQuestion;
use App\Models\CertificationQuestionOption;
use App\Models\User;
use App\Services\Admin\AdminAuthorization;
use App\Services\Notifications\CertificationAssessmentResultNotificationDispatcher;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CertificationAssessmentAttemptService
{
    public function __construct(
        private readonly CertificationLearningService $learning,
        private readonly AdminAuthorization $authorization,
        private readonly CertificationAwardService $awards,
        private readonly CertificationAssessmentResultNotificationDispatcher $resultNotifications,
    ) {}

    /**
     * Start a new attempt, or resume the existing in-progress attempt.
     *
     * @return array{attempt: CertificationAssessmentAttempt, created: bool}
     */
    public function start(User $user, CertificationEnrollment $enrollment): array
    {
        $enrollment = $this->learning->assertOwnedActiveEnrollment($user, $enrollment);
        $this->assertEligible($enrollment);
        $assessment = $this->requireAssessmentForEnrollment($enrollment);
        $this->assertAssessmentReady($assessment);

        return DB::transaction(function () use ($user, $enrollment, $assessment) {
            $lockedEnrollment = CertificationEnrollment::query()
                ->whereKey($enrollment->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->learning->assertOwnedActiveEnrollment($user, $lockedEnrollment);

            $existing = CertificationAssessmentAttempt::query()
                ->where('enrollment_id', $lockedEnrollment->id)
                ->where('assessment_id', $assessment->id)
                ->where('status', CertificationAssessmentAttemptStatus::InProgress)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return [
                    'attempt' => $existing->load(['assessment.programmeVersion', 'assessment.questions.options', 'answers']),
                    'created' => false,
                ];
            }

            $nextNumber = (int) CertificationAssessmentAttempt::query()
                ->where('enrollment_id', $lockedEnrollment->id)
                ->where('assessment_id', $assessment->id)
                ->max('attempt_number') + 1;

            $attempt = new CertificationAssessmentAttempt;
            $attempt->enrollment_id = $lockedEnrollment->id;
            $attempt->assessment_id = $assessment->id;
            $attempt->programme_version_id = $lockedEnrollment->programme_version_id;
            $attempt->attempt_number = $nextNumber;
            $attempt->status = CertificationAssessmentAttemptStatus::InProgress;
            $attempt->started_at = now();
            // Immutable snapshot from Programme Version (sole authoritative source — MH-BE-048).
            $attempt->pass_mark_percent = $assessment->authoritativePassMarkPercent();
            $attempt->save();

            $this->recordEvent(
                $user,
                $lockedEnrollment,
                $assessment,
                CertificationAdminEventAction::AssessmentAttemptStarted,
                [
                    'attempt_id' => $attempt->id,
                    'attempt_number' => $attempt->attempt_number,
                    'pass_mark_percent' => (string) $attempt->pass_mark_percent,
                ],
            );

            return [
                'attempt' => $attempt->refresh()->load(['assessment.programmeVersion', 'assessment.questions.options', 'answers']),
                'created' => true,
            ];
        });
    }

    /**
     * @param  list<array{question_id: int, selected_option_id: int}>  $answers
     */
    public function submit(
        User $user,
        CertificationEnrollment $enrollment,
        CertificationAssessmentAttempt $attempt,
        array $answers,
    ): CertificationAssessmentAttempt {
        $enrollment = $this->learning->assertOwnedActiveEnrollment($user, $enrollment);
        $this->assertAttemptOwnedByEnrollment($enrollment, $attempt);

        $newlySubmitted = false;

        $submitted = DB::transaction(function () use ($user, $enrollment, $attempt, $answers, &$newlySubmitted) {
            $locked = CertificationAssessmentAttempt::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertAttemptOwnedByEnrollment($enrollment, $locked);

            if ($locked->status === CertificationAssessmentAttemptStatus::Submitted) {
                return $locked->load(['assessment', 'answers']);
            }

            if ($locked->status !== CertificationAssessmentAttemptStatus::InProgress) {
                throw new HttpResponseException(ApiResponse::error(
                    ApiErrorCode::CONFLICT,
                    'This assessment attempt cannot be submitted.',
                    409,
                ));
            }

            $this->assertEligible($enrollment);

            $assessment = CertificationAssessment::query()
                ->whereKey($locked->assessment_id)
                ->with(['questions.options'])
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $assessment->programme_version_id !== (int) $enrollment->programme_version_id) {
                throw $this->notFound();
            }

            $questions = $assessment->questions;
            if ($questions->isEmpty()) {
                throw new HttpResponseException(ApiResponse::error(
                    ApiErrorCode::BUSINESS_VALIDATION,
                    'This assessment has no questions.',
                    422,
                ));
            }

            $normalized = $this->normalizeAndValidateAnswers($questions, $answers);
            $correctCount = 0;
            $total = $questions->count();

            $locked->answers()->delete();

            foreach ($normalized as $row) {
                $answer = new CertificationAttemptAnswer;
                $answer->attempt_id = $locked->id;
                $answer->question_id = $row['question_id'];
                $answer->selected_option_id = $row['selected_option_id'];
                $answer->is_correct = $row['is_correct'];
                $answer->save();
                if ($row['is_correct']) {
                    $correctCount++;
                }
            }

            $scorePercent = $this->calculateScorePercent($correctCount, $total);
            $passMark = number_format((float) $locked->pass_mark_percent, 2, '.', '');
            $passed = bccomp($scorePercent, $passMark, 2) >= 0;

            $locked->status = CertificationAssessmentAttemptStatus::Submitted;
            $locked->submitted_at = now();
            $locked->correct_count = $correctCount;
            $locked->total_questions = $total;
            $locked->score_percent = $scorePercent;
            $locked->passed = $passed;
            $locked->save();

            $this->recordEvent(
                $user,
                $enrollment,
                $assessment,
                CertificationAdminEventAction::AssessmentAttemptSubmitted,
                [
                    'attempt_id' => $locked->id,
                    'attempt_number' => $locked->attempt_number,
                    'correct_count' => $correctCount,
                    'total_questions' => $total,
                    'score_percent' => $scorePercent,
                    'passed' => $passed,
                ],
            );

            $this->recordEvent(
                $user,
                $enrollment,
                $assessment,
                $passed
                    ? CertificationAdminEventAction::AssessmentAttemptPassed
                    : CertificationAdminEventAction::AssessmentAttemptFailed,
                [
                    'attempt_id' => $locked->id,
                    'attempt_number' => $locked->attempt_number,
                    'score_percent' => $scorePercent,
                    'pass_mark_percent' => $passMark,
                ],
            );

            if ($passed) {
                $this->awards->createFromPassingAttempt($user, $enrollment, $locked->refresh());
            }

            $newlySubmitted = true;

            return $locked->refresh()->load(['assessment', 'answers']);
        });

        // Dispatch only after the attempt result (+ optional award) is committed.
        // BaseNotification also uses afterCommit; skip on idempotent re-submit.
        if ($newlySubmitted) {
            $this->resultNotifications->notifyResult($submitted);
        }

        return $submitted;
    }

    /**
     * @return Collection<int, CertificationAssessmentAttempt>
     */
    public function indexForEnrollment(User $user, CertificationEnrollment $enrollment): Collection
    {
        $enrollment = $this->learning->assertOwnedActiveEnrollment($user, $enrollment);

        return CertificationAssessmentAttempt::query()
            ->where('enrollment_id', $enrollment->id)
            ->orderByDesc('attempt_number')
            ->get();
    }

    public function showForEnrollment(
        User $user,
        CertificationEnrollment $enrollment,
        CertificationAssessmentAttempt $attempt,
    ): CertificationAssessmentAttempt {
        $enrollment = $this->learning->assertOwnedActiveEnrollment($user, $enrollment);
        $this->assertAttemptOwnedByEnrollment($enrollment, $attempt);

        $relations = ['assessment'];
        if ($attempt->status === CertificationAssessmentAttemptStatus::InProgress) {
            $relations[] = 'assessment.questions.options';
            $relations[] = 'answers';
        } else {
            $relations[] = 'answers';
        }

        return $attempt->load($relations);
    }

    /**
     * @return Collection<int, CertificationAssessmentAttempt>
     */
    public function adminIndex(User $admin, CertificationEnrollment $enrollment): Collection
    {
        $this->authorization->assert($admin, AdminPermission::CertificationLearnersView);

        return CertificationAssessmentAttempt::query()
            ->where('enrollment_id', $enrollment->id)
            ->with(['assessment'])
            ->orderByDesc('attempt_number')
            ->get();
    }

    public function adminShow(
        User $admin,
        CertificationEnrollment $enrollment,
        CertificationAssessmentAttempt $attempt,
    ): CertificationAssessmentAttempt {
        $this->authorization->assert($admin, AdminPermission::CertificationLearnersView);
        $this->assertAttemptOwnedByEnrollment($enrollment, $attempt);

        return $attempt->load(['assessment', 'answers.question', 'answers.selectedOption']);
    }

    private function assertEligible(CertificationEnrollment $enrollment): void
    {
        $eligibility = $this->learning->assessmentEligibilityForEnrollment($enrollment);
        if (! $eligibility['eligible']) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'Complete all required lessons before taking the assessment.',
                409,
            ));
        }
    }

    private function requireAssessmentForEnrollment(CertificationEnrollment $enrollment): CertificationAssessment
    {
        $assessment = CertificationAssessment::query()
            ->where('programme_version_id', $enrollment->programme_version_id)
            ->with(['questions.options', 'programmeVersion'])
            ->first();

        if ($assessment === null) {
            throw $this->notFound();
        }

        return $assessment;
    }

    private function assertAssessmentReady(CertificationAssessment $assessment): void
    {
        if ($assessment->authoritativePassMarkPercent() === null) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::BUSINESS_VALIDATION,
                'This programme version is not configured with a pass mark.',
                422,
            ));
        }

        if ($assessment->questions->isEmpty()) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::BUSINESS_VALIDATION,
                'This assessment has no questions.',
                422,
            ));
        }
    }

    private function assertAttemptOwnedByEnrollment(
        CertificationEnrollment $enrollment,
        CertificationAssessmentAttempt $attempt,
    ): void {
        if ((int) $attempt->enrollment_id !== (int) $enrollment->id) {
            throw $this->notFound();
        }

        if ((int) $attempt->programme_version_id !== (int) $enrollment->programme_version_id) {
            throw $this->notFound();
        }
    }

    /**
     * @param  Collection<int, CertificationQuestion>  $questions
     * @param  list<array{question_id: int|string, selected_option_id: int|string}>  $answers
     * @return list<array{question_id: int, selected_option_id: int, is_correct: bool}>
     */
    private function normalizeAndValidateAnswers(Collection $questions, array $answers): array
    {
        $byQuestionId = $questions->keyBy(fn (CertificationQuestion $q) => (int) $q->id);
        $expectedIds = $byQuestionId->keys()->map(fn ($id) => (int) $id)->sort()->values()->all();

        $seen = [];
        $normalized = [];

        foreach ($answers as $index => $answer) {
            $questionId = (int) ($answer['question_id'] ?? 0);
            $optionId = (int) ($answer['selected_option_id'] ?? 0);

            if ($questionId < 1 || $optionId < 1) {
                throw ValidationException::withMessages([
                    "answers.{$index}" => ['Each answer requires question_id and selected_option_id.'],
                ]);
            }

            if (isset($seen[$questionId])) {
                throw ValidationException::withMessages([
                    'answers' => ['Each question may be answered only once.'],
                ]);
            }
            $seen[$questionId] = true;

            /** @var CertificationQuestion|null $question */
            $question = $byQuestionId->get($questionId);
            if ($question === null) {
                throw ValidationException::withMessages([
                    "answers.{$index}.question_id" => ['Question does not belong to this assessment.'],
                ]);
            }

            /** @var CertificationQuestionOption|null $option */
            $option = $question->options->firstWhere('id', $optionId);
            if ($option === null) {
                throw ValidationException::withMessages([
                    "answers.{$index}.selected_option_id" => ['Selected option does not belong to this question.'],
                ]);
            }

            $normalized[] = [
                'question_id' => $questionId,
                'selected_option_id' => $optionId,
                'is_correct' => (bool) $option->is_correct,
            ];
        }

        $answeredIds = array_keys($seen);
        sort($answeredIds);
        if ($answeredIds !== $expectedIds) {
            throw ValidationException::withMessages([
                'answers' => ['Answers must include exactly one response for every assessment question.'],
            ]);
        }

        return $normalized;
    }

    /**
     * Equal-weight scoring: (correct / total) × 100, rounded half-up to 2 decimal places
     * using integer arithmetic (no floating-point boundary drift).
     */
    private function calculateScorePercent(int $correct, int $total): string
    {
        if ($total <= 0) {
            return '0.00';
        }

        $hundredths = intdiv(($correct * 10000) + intdiv($total, 2), $total);

        return sprintf('%d.%02d', intdiv($hundredths, 100), $hundredths % 100);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function recordEvent(
        User $actor,
        CertificationEnrollment $enrollment,
        CertificationAssessment $assessment,
        CertificationAdminEventAction $action,
        ?array $payload = null,
    ): void {
        $event = new CertificationAdminEvent;
        $event->actor_user_id = $actor->id;
        $event->programme_id = $enrollment->programme_id;
        $event->programme_version_id = $enrollment->programme_version_id;
        $event->action = $action;
        $event->payload = array_merge([
            'enrollment_id' => $enrollment->id,
            'assessment_id' => $assessment->id,
        ], $payload ?? []);
        $event->save();
    }

    private function notFound(): HttpResponseException
    {
        return new HttpResponseException(ApiResponse::error(
            ApiErrorCode::NOT_FOUND,
            'The requested resource was not found.',
            404,
        ));
    }
}
