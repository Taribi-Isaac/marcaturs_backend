<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\CertificationEnrollment;
use Illuminate\Notifications\Messages\MailMessage;

class CertificationEnrollmentActivatedNotification extends BaseNotification
{
    public function __construct(
        private readonly int $enrollmentId,
        private readonly int $recipientUserId,
    ) {
        parent::__construct();
    }

    public function notificationType(): NotificationType
    {
        return NotificationType::CertificationEnrollmentActivated;
    }

    public function idempotencyKey(): ?string
    {
        return sprintf(
            'certification_enrollment:%d:%s:%d',
            $this->enrollmentId,
            NotificationType::CertificationEnrollmentActivated->value,
            $this->recipientUserId,
        );
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        unset($channel);

        return CertificationEnrollment::query()->whereKey($this->enrollmentId)->exists();
    }

    public function toMail(mixed $notifiable): ?MailMessage
    {
        $enrollment = $this->loadEnrollment();
        $programmeName = $enrollment->programme?->name ?? 'Certification programme';

        return (new MailMessage)
            ->subject('MarcatursHub: Certification enrollment confirmed')
            ->line('Your payment was confirmed and your certification enrollment is now active.')
            ->line('Programme: '.$programmeName)
            ->line('You can begin learning when the learning experience is available in your account.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        $enrollment = $this->loadEnrollment();

        return [
            'enrollment_id' => $enrollment->id,
            'programme_id' => $enrollment->programme_id,
            'programme_version_id' => $enrollment->programme_version_id,
            'programme_name' => $enrollment->programme?->name,
            'enrolled_at' => $enrollment->enrolled_at?->toIso8601String(),
            'fee_amount_minor' => $enrollment->fee_amount_minor,
            'fee_currency' => $enrollment->fee_currency,
        ];
    }

    private function loadEnrollment(): CertificationEnrollment
    {
        return CertificationEnrollment::query()
            ->with('programme')
            ->findOrFail($this->enrollmentId);
    }
}
