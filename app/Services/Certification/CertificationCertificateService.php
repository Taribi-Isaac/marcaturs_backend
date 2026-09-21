<?php

namespace App\Services\Certification;

use App\Enums\AdminPermission;
use App\Enums\CertificationAdminEventAction;
use App\Enums\CertificationCertificateArtifactStatus;
use App\Enums\CertificationCertificateStatus;
use App\Models\CertificationAdminEvent;
use App\Models\CertificationAward;
use App\Models\CertificationCertificate;
use App\Models\User;
use App\Services\Admin\AdminAuthorization;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CertificationCertificateService
{
    public function __construct(
        private readonly AdminAuthorization $authorization,
        private readonly CertificationCertificateArtifactService $artifacts,
    ) {}

    /**
     * Register the Certificate record for an Award (FR-039).
     * Idempotent: one Certificate per Award. PDF generation is dispatched after commit.
     */
    public function registerForAward(User $actor, CertificationAward $award): CertificationCertificate
    {
        $existing = CertificationCertificate::query()
            ->where('award_id', $award->id)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            if ($existing->artifact_status === CertificationCertificateArtifactStatus::PendingGeneration
                || $existing->artifact_status === CertificationCertificateArtifactStatus::FailedRetryable) {
                $certificateId = $existing->id;
                DB::afterCommit(function () use ($certificateId): void {
                    $fresh = CertificationCertificate::query()->find($certificateId);
                    if ($fresh !== null) {
                        $this->artifacts->dispatchGeneration($fresh);
                    }
                });
            }

            return $existing;
        }

        $award->loadMissing(['user', 'programme', 'programmeVersion']);

        if ($award->user === null || $award->programme === null || $award->programmeVersion === null) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::SERVER_ERROR,
                'Certification award context is incomplete for certificate registration.',
                500,
            ));
        }

        try {
            $certificate = new CertificationCertificate;
            $certificate->award_id = $award->id;
            $certificate->certificate_number = $this->newCertificateNumber();
            $certificate->status = CertificationCertificateStatus::Issued;
            $certificate->issued_at = now();
            $certificate->recipient_name = (string) $award->user->name;
            $certificate->programme_name = (string) $award->programme->name;
            $certificate->programme_version_number = (int) $award->programmeVersion->version_number;
            $certificate->issuer_name = (string) config('certification.certificate_issuer');
            $certificate->artifact_status = CertificationCertificateArtifactStatus::PendingGeneration;
            $certificate->save();

            $this->recordEvent($actor, $award, $certificate);

            $certificateId = $certificate->id;
            DB::afterCommit(function () use ($certificateId): void {
                $fresh = CertificationCertificate::query()->find($certificateId);
                if ($fresh !== null) {
                    $this->artifacts->dispatchGeneration($fresh);
                }
            });

            return $certificate->refresh();
        } catch (UniqueConstraintViolationException) {
            return CertificationCertificate::query()
                ->where('award_id', $award->id)
                ->firstOrFail();
        }
    }

    /**
     * @return Collection<int, CertificationCertificate>
     */
    public function indexForAmbassador(User $user): Collection
    {
        $this->assertAmbassador($user);

        return CertificationCertificate::query()
            ->whereHas('award', fn ($query) => $query->where('user_id', $user->id))
            ->with(['award.programme', 'award.programmeVersion'])
            ->orderByDesc('id')
            ->get();
    }

    public function showForAmbassador(User $user, CertificationCertificate $certificate): CertificationCertificate
    {
        $this->assertAmbassador($user);
        $certificate->loadMissing('award');

        if ($certificate->award === null || (int) $certificate->award->user_id !== (int) $user->id) {
            throw $this->notFound();
        }

        return $certificate->load(['award.programme', 'award.programmeVersion', 'award.assessmentAttempt']);
    }

    /**
     * @return Collection<int, CertificationCertificate>
     */
    public function adminIndexForEnrollment(User $admin, int $enrollmentId): Collection
    {
        $this->authorization->assert($admin, AdminPermission::CertificationLearnersView);

        return CertificationCertificate::query()
            ->whereHas('award', fn ($query) => $query->where('enrollment_id', $enrollmentId))
            ->with(['award.user', 'award.programme', 'award.programmeVersion'])
            ->orderByDesc('id')
            ->get();
    }

    public function adminShow(User $admin, CertificationCertificate $certificate): CertificationCertificate
    {
        $this->authorization->assert($admin, AdminPermission::CertificationLearnersView);

        return $certificate->load(['award.user', 'award.programme', 'award.programmeVersion', 'award.assessmentAttempt']);
    }

    private function newCertificateNumber(): string
    {
        // Opaque unique identifier (FR-042). Branded human format remains a Product decision.
        return 'mhcert_'.strtolower((string) Str::ulid());
    }

    private function assertAmbassador(User $user): void
    {
        if (! $user->isAmbassador()) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }
    }

    private function recordEvent(
        User $actor,
        CertificationAward $award,
        CertificationCertificate $certificate,
    ): void {
        $event = new CertificationAdminEvent;
        $event->actor_user_id = $actor->id;
        $event->programme_id = $award->programme_id;
        $event->programme_version_id = $award->programme_version_id;
        $event->action = CertificationAdminEventAction::CertificateCreated;
        $event->payload = [
            'certificate_id' => $certificate->id,
            'award_id' => $award->id,
            'certificate_number' => $certificate->certificate_number,
            'issued_at' => $certificate->issued_at?->toIso8601String(),
            'status' => $certificate->status->value,
            'artifact_status' => $certificate->artifact_status->value,
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
