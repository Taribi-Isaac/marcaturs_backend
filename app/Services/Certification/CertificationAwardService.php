<?php

namespace App\Services\Certification;

use App\Enums\AdminPermission;
use App\Enums\CertificationAdminEventAction;
use App\Enums\CertificationAssessmentAttemptStatus;
use App\Enums\CertificationAwardStatus;
use App\Models\CertificationAdminEvent;
use App\Models\CertificationAssessmentAttempt;
use App\Models\CertificationAward;
use App\Models\CertificationEnrollment;
use App\Models\User;
use App\Services\Admin\AdminAuthorization;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;

class CertificationAwardService
{
    public function __construct(
        private readonly CertificationLearningService $learning,
        private readonly AdminAuthorization $authorization,
        private readonly CertificationCertificateService $certificates,
    ) {}

    /**
     * Create an Award from a submitted passing attempt when requirements are met.
     *
     * Idempotent: returns the existing Award for this enrolment/version if present.
     * The Award always references the first qualifying passing attempt that created it.
     */
    public function createFromPassingAttempt(
        User $actor,
        CertificationEnrollment $enrollment,
        CertificationAssessmentAttempt $attempt,
    ): ?CertificationAward {
        if ($attempt->status !== CertificationAssessmentAttemptStatus::Submitted) {
            return null;
        }

        if ($attempt->passed !== true) {
            return null;
        }

        if ((int) $attempt->enrollment_id !== (int) $enrollment->id) {
            throw $this->notFound();
        }

        if ((int) $attempt->programme_version_id !== (int) $enrollment->programme_version_id) {
            throw $this->notFound();
        }

        // FR-036: re-verify required learning before issuing the award.
        $eligibility = $this->learning->assessmentEligibilityForEnrollment($enrollment);
        if (! $eligibility['eligible']) {
            return null;
        }

        $existing = CertificationAward::query()
            ->where('user_id', $enrollment->user_id)
            ->where('programme_version_id', $enrollment->programme_version_id)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            $this->certificates->registerForAward($actor, $existing);

            return $existing;
        }

        try {
            $award = new CertificationAward;
            $award->user_id = $enrollment->user_id;
            $award->programme_id = $enrollment->programme_id;
            $award->programme_version_id = $enrollment->programme_version_id;
            $award->enrollment_id = $enrollment->id;
            $award->assessment_attempt_id = $attempt->id;
            $award->status = CertificationAwardStatus::Awarded;
            $award->awarded_at = now();
            $award->save();

            $this->recordEvent($actor, $enrollment, $award, $attempt);
            $this->certificates->registerForAward($actor, $award->refresh());

            return $award->refresh();
        } catch (UniqueConstraintViolationException) {
            $award = CertificationAward::query()
                ->where('user_id', $enrollment->user_id)
                ->where('programme_version_id', $enrollment->programme_version_id)
                ->first();

            if ($award !== null) {
                $this->certificates->registerForAward($actor, $award);
            }

            return $award;
        }
    }

    /**
     * @return Collection<int, CertificationAward>
     */
    public function indexForAmbassador(User $user): Collection
    {
        $this->assertAmbassador($user);

        return CertificationAward::query()
            ->where('user_id', $user->id)
            ->with(['programme', 'programmeVersion', 'assessmentAttempt'])
            ->orderByDesc('id')
            ->get();
    }

    public function showForAmbassador(User $user, CertificationAward $award): CertificationAward
    {
        $this->assertAmbassador($user);

        if ((int) $award->user_id !== (int) $user->id) {
            throw $this->notFound();
        }

        return $award->load(['programme', 'programmeVersion', 'assessmentAttempt', 'enrollment']);
    }

    /**
     * @return Collection<int, CertificationAward>
     */
    public function adminIndexForEnrollment(User $admin, CertificationEnrollment $enrollment): Collection
    {
        $this->authorization->assert($admin, AdminPermission::CertificationLearnersView);

        return CertificationAward::query()
            ->where('enrollment_id', $enrollment->id)
            ->with(['user', 'programme', 'programmeVersion', 'assessmentAttempt'])
            ->orderByDesc('id')
            ->get();
    }

    public function adminShow(User $admin, CertificationAward $award): CertificationAward
    {
        $this->authorization->assert($admin, AdminPermission::CertificationLearnersView);

        return $award->load(['user', 'programme', 'programmeVersion', 'assessmentAttempt', 'enrollment']);
    }

    private function assertAmbassador(User $user): void
    {
        if (! $user->isAmbassador()) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }
    }

    private function recordEvent(
        User $actor,
        CertificationEnrollment $enrollment,
        CertificationAward $award,
        CertificationAssessmentAttempt $attempt,
    ): void {
        $event = new CertificationAdminEvent;
        $event->actor_user_id = $actor->id;
        $event->programme_id = $enrollment->programme_id;
        $event->programme_version_id = $enrollment->programme_version_id;
        $event->action = CertificationAdminEventAction::AwardCreated;
        $event->payload = [
            'award_id' => $award->id,
            'enrollment_id' => $enrollment->id,
            'assessment_attempt_id' => $attempt->id,
            'user_id' => $award->user_id,
            'awarded_at' => $award->awarded_at?->toIso8601String(),
            'status' => $award->status->value,
        ];
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
