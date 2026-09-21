<?php

namespace App\Services\Certification;

use App\Enums\AdminPermission;
use App\Enums\CertificationAdminEventAction;
use App\Enums\CertificationCertificateArtifactStatus;
use App\Enums\CertificationCertificateStatus;
use App\Jobs\Certification\GenerateCertificationCertificateArtifactJob;
use App\Models\CertificationAdminEvent;
use App\Models\CertificationCertificate;
use App\Models\User;
use App\Services\Admin\AdminAuthorization;
use App\Services\Notifications\CertificationCertificateNotificationDispatcher;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CertificationCertificateArtifactService
{
    public function __construct(
        private readonly CertificationCertificatePdfRenderer $renderer,
        private readonly CertificationCertificateArtifactStore $store,
        private readonly CertificationCertificateNotificationDispatcher $notifications,
        private readonly AdminAuthorization $authorization,
    ) {}

    public function dispatchGeneration(CertificationCertificate $certificate): void
    {
        GenerateCertificationCertificateArtifactJob::dispatch($certificate->id);
    }

    public function generateForCertificateId(int $certificateId): void
    {
        try {
            DB::transaction(function () use ($certificateId): void {
                $certificate = CertificationCertificate::query()
                    ->whereKey($certificateId)
                    ->lockForUpdate()
                    ->first();

                if ($certificate === null) {
                    return;
                }

                if ($certificate->status !== CertificationCertificateStatus::Issued) {
                    return;
                }

                if ($certificate->artifact_status === CertificationCertificateArtifactStatus::Generated
                    && $this->store->exists($certificate)) {
                    return;
                }

                $previousDisk = $certificate->artifact_disk;
                $previousPath = $certificate->artifact_path;

                $binary = $this->renderer->render($certificate);
                $stored = $this->store->store($certificate, $binary);

                $certificate->artifact_status = CertificationCertificateArtifactStatus::Generated;
                $certificate->artifact_disk = $stored['disk'];
                $certificate->artifact_path = $stored['path'];
                $certificate->artifact_generated_at = now();
                $certificate->artifact_failed_at = null;
                $certificate->artifact_error_code = null;
                $certificate->save();

                $this->recordEvent($certificate, CertificationAdminEventAction::CertificateArtifactGenerated, [
                    'certificate_id' => $certificate->id,
                    'award_id' => $certificate->award_id,
                    'artifact_status' => $certificate->artifact_status->value,
                    'artifact_generated_at' => $certificate->artifact_generated_at?->toIso8601String(),
                ]);

                $certificateIdForNotify = $certificate->id;
                DB::afterCommit(function () use ($certificateIdForNotify, $previousDisk, $previousPath): void {
                    $this->store->deleteIfPresent($previousDisk, $previousPath);

                    $fresh = CertificationCertificate::query()->find($certificateIdForNotify);
                    if ($fresh !== null) {
                        $this->notifications->notifyAvailable($fresh);
                    }
                });
            });
        } catch (Throwable $exception) {
            Log::warning('Certification certificate artifact generation failed', [
                'certificate_id' => $certificateId,
                'error_class' => $exception::class,
            ]);

            // Persist failure outside the rolled-back generation transaction (FR-069).
            $this->markFailedRetryable($certificateId, 'generation_failed');

            throw $exception;
        }
    }

    public function markFailedRetryable(int $certificateId, string $errorCode): void
    {
        DB::transaction(function () use ($certificateId, $errorCode): void {
            $certificate = CertificationCertificate::query()
                ->whereKey($certificateId)
                ->lockForUpdate()
                ->first();

            if ($certificate === null) {
                return;
            }

            if ($certificate->artifact_status === CertificationCertificateArtifactStatus::Generated
                && $this->store->exists($certificate)) {
                return;
            }

            $certificate->artifact_status = CertificationCertificateArtifactStatus::FailedRetryable;
            $certificate->artifact_failed_at = now();
            $certificate->artifact_error_code = Str::limit($errorCode, 64, '');
            $certificate->save();

            $this->recordEvent($certificate, CertificationAdminEventAction::CertificateArtifactGenerationFailed, [
                'certificate_id' => $certificate->id,
                'award_id' => $certificate->award_id,
                'artifact_status' => $certificate->artifact_status->value,
                'artifact_error_code' => $certificate->artifact_error_code,
            ]);
        });
    }

    public function retryGeneration(User $admin, CertificationCertificate $certificate): CertificationCertificate
    {
        $this->authorization->assert($admin, AdminPermission::CertificationManage);

        return DB::transaction(function () use ($certificate) {
            $locked = CertificationCertificate::query()
                ->whereKey($certificate->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->artifact_status === CertificationCertificateArtifactStatus::Generated
                && $this->store->exists($locked)) {
                return $locked;
            }

            $locked->artifact_status = CertificationCertificateArtifactStatus::PendingGeneration;
            $locked->artifact_failed_at = null;
            $locked->artifact_error_code = null;
            $locked->save();

            $id = $locked->id;
            DB::afterCommit(function () use ($id): void {
                $fresh = CertificationCertificate::query()->find($id);
                if ($fresh !== null) {
                    $this->dispatchGeneration($fresh);
                }
            });

            return $locked->refresh();
        });
    }

    public function downloadForAmbassador(User $user, CertificationCertificate $certificate): StreamedResponse
    {
        if (! $user->isAmbassador()) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }

        $certificate->loadMissing('award');
        if ($certificate->award === null || (int) $certificate->award->user_id !== (int) $user->id) {
            throw $this->notFound();
        }

        return $this->streamArtifact($certificate, recordDownload: true, actor: $user);
    }

    public function downloadForAdmin(User $admin, CertificationCertificate $certificate): StreamedResponse
    {
        $this->authorization->assert($admin, AdminPermission::CertificationLearnersView);

        return $this->streamArtifact($certificate, recordDownload: true, actor: $admin);
    }

    private function streamArtifact(
        CertificationCertificate $certificate,
        bool $recordDownload,
        User $actor,
    ): StreamedResponse {
        if ($certificate->artifact_status !== CertificationCertificateArtifactStatus::Generated
            || ! $this->store->exists($certificate)) {
            $message = match ($certificate->artifact_status) {
                CertificationCertificateArtifactStatus::FailedRetryable => 'The certificate file is temporarily unavailable. Please try again later.',
                default => 'The certificate file is still being prepared.',
            };

            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                $message,
                409,
            ));
        }

        if ($recordDownload) {
            $this->recordEvent(
                $certificate,
                CertificationAdminEventAction::CertificateArtifactDownloaded,
                [
                    'certificate_id' => $certificate->id,
                    'award_id' => $certificate->award_id,
                    'actor_user_id' => $actor->id,
                ],
                $actor,
            );
        }

        $filename = sprintf('marcaturshub-certificate-%s.pdf', $certificate->certificate_number);

        return $this->store->stream($certificate, $filename);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordEvent(
        CertificationCertificate $certificate,
        CertificationAdminEventAction $action,
        array $payload,
        ?User $actor = null,
    ): void {
        $certificate->loadMissing('award');

        $event = new CertificationAdminEvent;
        $event->actor_user_id = $actor?->id ?? $certificate->award?->user_id;
        $event->programme_id = $certificate->award?->programme_id;
        $event->programme_version_id = $certificate->award?->programme_version_id;
        $event->action = $action;
        $event->payload = $payload;
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
