<?php

namespace App\Notifications;

use App\Enums\CertificationCertificateArtifactStatus;
use App\Enums\NotificationType;
use App\Models\CertificationCertificate;
use Illuminate\Notifications\Messages\MailMessage;

class CertificationCertificateAvailableNotification extends BaseNotification
{
    public function __construct(
        private readonly int $certificateId,
        private readonly int $recipientUserId,
    ) {
        parent::__construct();
    }

    public function notificationType(): NotificationType
    {
        return NotificationType::CertificationCertificateAvailable;
    }

    public function idempotencyKey(): ?string
    {
        return sprintf(
            'certification_certificate:%d:%s:%d',
            $this->certificateId,
            NotificationType::CertificationCertificateAvailable->value,
            $this->recipientUserId,
        );
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        unset($channel);

        return CertificationCertificate::query()
            ->whereKey($this->certificateId)
            ->where('artifact_status', CertificationCertificateArtifactStatus::Generated)
            ->exists();
    }

    public function toMail(mixed $notifiable): ?MailMessage
    {
        $certificate = $this->loadCertificate();

        return (new MailMessage)
            ->subject('MarcatursHub: Your certification certificate is available')
            ->line('Your certification certificate PDF is now available to download in your MarcatursHub account.')
            ->line('Programme: '.$certificate->programme_name)
            ->line('Certificate ID: '.$certificate->certificate_number)
            ->line('Open your certification certificates area to download it.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        $certificate = $this->loadCertificate();

        return [
            'certificate_id' => $certificate->id,
            'award_id' => $certificate->award_id,
            'certificate_number' => $certificate->certificate_number,
            'programme_name' => $certificate->programme_name,
            'programme_version_number' => $certificate->programme_version_number,
            'issued_at' => $certificate->issued_at?->toIso8601String(),
        ];
    }

    private function loadCertificate(): CertificationCertificate
    {
        return CertificationCertificate::query()->findOrFail($this->certificateId);
    }
}
