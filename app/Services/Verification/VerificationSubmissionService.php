<?php

namespace App\Services\Verification;

use App\Enums\Role;
use App\Enums\VerificationRequirementType;
use App\Enums\VerificationReviewAction;
use App\Enums\VerificationSubmissionStatus;
use App\Models\User;
use App\Models\VerificationRequirement;
use App\Models\VerificationReviewEvent;
use App\Models\VerificationSubmission;
use App\Models\VerificationSubmissionVersion;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class VerificationSubmissionService
{
    public function __construct(
        private readonly VerificationEvidenceStore $evidence,
    ) {}

    public function submit(User $user, int $requirementId, ?string $textValue, ?UploadedFile $file): VerificationSubmission
    {
        $this->assertParticipant($user);
        $this->assertProfileComplete($user);

        $requirement = VerificationRequirement::query()
            ->active()
            ->forParticipant($user->role)
            ->whereKey($requirementId)
            ->firstOrFail();

        if ($user->verificationSubmissions()->where('verification_requirement_id', $requirement->id)->exists()) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'A submission already exists for this requirement. Resubmit the existing submission instead.',
                409,
            ));
        }

        $this->assertPayload($requirement, $textValue, $file);

        return DB::transaction(function () use ($user, $requirement, $textValue, $file) {
            $submission = new VerificationSubmission;
            $submission->user_id = $user->id;
            $submission->verification_requirement_id = $requirement->id;
            $submission->status = VerificationSubmissionStatus::Pending;
            $submission->text_value = $textValue;
            $submission->current_version = 1;
            $submission->submitted_at = now();
            $submission->save();

            $version = $this->recordVersion($submission);
            if ($file instanceof UploadedFile) {
                $this->evidence->store($submission, $version, $file);
            }

            $this->recordEvent(
                $submission,
                $version,
                $user,
                VerificationReviewAction::Submitted,
                null,
                VerificationSubmissionStatus::Pending,
                null,
                null,
            );

            return $submission->load(['requirement', 'evidence', 'versions']);
        });
    }

    public function resubmit(User $user, VerificationSubmission $submission, ?string $textValue, ?UploadedFile $file): VerificationSubmission
    {
        $this->assertOwner($user, $submission);

        if (! $submission->status->allowsResubmission()) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'This submission cannot be resubmitted in its current state.',
                409,
            ));
        }

        $requirement = $submission->requirement;
        if ($requirement === null || ! $requirement->is_active) {
            throw new ModelNotFoundException;
        }

        $this->assertPayload($requirement, $textValue, $file);

        return DB::transaction(function () use ($user, $submission, $textValue, $file) {
            $previous = $submission->status;
            $submission->status = VerificationSubmissionStatus::Pending;
            $submission->text_value = $textValue;
            $submission->current_version = $submission->current_version + 1;
            $submission->review_reason = null;
            $submission->reviewer_notes = null;
            $submission->reviewed_by = null;
            $submission->reviewed_at = null;
            $submission->submitted_at = now();
            $submission->save();

            $version = $this->recordVersion($submission);
            if ($file instanceof UploadedFile) {
                $this->evidence->store($submission, $version, $file);
            }

            $this->recordEvent(
                $submission,
                $version,
                $user,
                VerificationReviewAction::Resubmitted,
                $previous,
                VerificationSubmissionStatus::Pending,
                null,
                null,
            );

            return $submission->load(['requirement', 'evidence', 'versions']);
        });
    }

    public function startReview(User $admin, VerificationSubmission $submission): VerificationSubmission
    {
        $this->assertAdmin($admin);

        if ($submission->status !== VerificationSubmissionStatus::Pending) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'Only pending submissions can be moved to review.',
                409,
            ));
        }

        return $this->applyDecision(
            $admin,
            $submission,
            VerificationSubmissionStatus::UnderReview,
            VerificationReviewAction::StartedReview,
            null,
            null,
        );
    }

    public function approve(User $admin, VerificationSubmission $submission, ?string $notes): VerificationSubmission
    {
        return $this->decide(
            $admin,
            $submission,
            VerificationSubmissionStatus::Approved,
            VerificationReviewAction::Approved,
            null,
            $notes,
        );
    }

    public function reject(User $admin, VerificationSubmission $submission, string $reason, ?string $notes): VerificationSubmission
    {
        return $this->decide(
            $admin,
            $submission,
            VerificationSubmissionStatus::Rejected,
            VerificationReviewAction::Rejected,
            $reason,
            $notes,
        );
    }

    public function requestInformation(User $admin, VerificationSubmission $submission, string $reason, ?string $notes): VerificationSubmission
    {
        return $this->decide(
            $admin,
            $submission,
            VerificationSubmissionStatus::MoreInformationRequired,
            VerificationReviewAction::RequestedInformation,
            $reason,
            $notes,
        );
    }

    private function decide(
        User $admin,
        VerificationSubmission $submission,
        VerificationSubmissionStatus $next,
        VerificationReviewAction $action,
        ?string $reason,
        ?string $notes,
    ): VerificationSubmission {
        $this->assertAdmin($admin);

        if (! $submission->status->allowsReview()) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'This submission cannot be reviewed in its current state.',
                409,
            ));
        }

        return $this->applyDecision($admin, $submission, $next, $action, $reason, $notes);
    }

    private function applyDecision(
        User $admin,
        VerificationSubmission $submission,
        VerificationSubmissionStatus $next,
        VerificationReviewAction $action,
        ?string $reason,
        ?string $notes,
    ): VerificationSubmission {
        return DB::transaction(function () use ($admin, $submission, $next, $action, $reason, $notes) {
            $previous = $submission->status;
            $submission->status = $next;
            $submission->reviewed_by = $admin->id;
            $submission->reviewed_at = now();
            $submission->review_reason = $reason;
            $submission->reviewer_notes = $notes;
            $submission->save();

            $this->recordEvent(
                $submission,
                $submission->versions()->where('version', $submission->current_version)->first(),
                $admin,
                $action,
                $previous,
                $next,
                $reason,
                $notes,
            );

            return $submission->load(['requirement', 'evidence', 'user', 'versions']);
        });
    }

    private function recordVersion(VerificationSubmission $submission): VerificationSubmissionVersion
    {
        $version = new VerificationSubmissionVersion;
        $version->verification_submission_id = $submission->id;
        $version->version = $submission->current_version;
        $version->text_value = $submission->text_value;
        $version->status = $submission->status;
        $version->save();

        return $version;
    }

    private function recordEvent(
        VerificationSubmission $submission,
        ?VerificationSubmissionVersion $version,
        User $actor,
        VerificationReviewAction $action,
        ?VerificationSubmissionStatus $previous,
        VerificationSubmissionStatus $next,
        ?string $reason,
        ?string $notes,
    ): void {
        $event = new VerificationReviewEvent;
        $event->verification_submission_id = $submission->id;
        $event->verification_submission_version_id = $version?->id;
        $event->actor_id = $actor->id;
        $event->action = $action;
        $event->previous_status = $previous;
        $event->new_status = $next;
        $event->reason = $reason;
        $event->reviewer_notes = $notes;
        $event->save();
    }

    private function assertParticipant(User $user): void
    {
        if (! $user->isBusiness() && ! $user->isAmbassador()) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }
    }

    private function assertAdmin(User $user): void
    {
        if (! $user->isAdmin()) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }
    }

    private function assertOwner(User $user, VerificationSubmission $submission): void
    {
        if ($submission->user_id !== $user->id) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }
    }

    private function assertProfileComplete(User $user): void
    {
        $complete = match ($user->role) {
            Role::Business => $user->businessProfile()->exists(),
            Role::Ambassador => $user->ambassadorProfile()->exists(),
            default => false,
        };

        if (! $complete) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::BUSINESS_VALIDATION,
                'Complete your profile before submitting verification.',
                422,
            ));
        }
    }

    private function assertPayload(VerificationRequirement $requirement, ?string $textValue, ?UploadedFile $file): void
    {
        if ($requirement->requirement_type === VerificationRequirementType::Document) {
            if (! $file instanceof UploadedFile) {
                throw new HttpResponseException(ApiResponse::validation(
                    validator(['evidence' => null], ['evidence' => 'required|file']),
                ));
            }

            return;
        }

        $rules = ['text_value' => ['required', 'string', 'max:5000']];

        if ($requirement->requirement_type === VerificationRequirementType::Email) {
            $rules['text_value'][] = 'email';
        }

        $validator = validator(['text_value' => $textValue], $rules);

        if ($validator->fails()) {
            throw new HttpResponseException(ApiResponse::validation($validator));
        }
    }
}
