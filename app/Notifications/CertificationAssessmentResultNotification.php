<?php

namespace App\Notifications;

use App\Enums\CertificationAssessmentAttemptStatus;
use App\Enums\NotificationType;
use App\Models\CertificationAssessmentAttempt;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Transactional assessment-result notification (SoT §39 — Assessment Result).
 *
 * Communicates pass/fail for a completed attempt. Does not create awards or
 * certificates and does not replace enrollment / certificate-available mail.
 */
class CertificationAssessmentResultNotification extends BaseNotification
{
    public function __construct(
        private readonly int $attemptId,
        private readonly int $recipientUserId,
    ) {
        parent::__construct();
    }

    public function notificationType(): NotificationType
    {
        return NotificationType::CertificationAssessmentResult;
    }

    public function idempotencyKey(): ?string
    {
        return sprintf(
            'certification_assessment_attempt:%d:%s:%d',
            $this->attemptId,
            NotificationType::CertificationAssessmentResult->value,
            $this->recipientUserId,
        );
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        unset($channel);

        return CertificationAssessmentAttempt::query()
            ->whereKey($this->attemptId)
            ->where('status', CertificationAssessmentAttemptStatus::Submitted)
            ->whereNotNull('submitted_at')
            ->exists();
    }

    public function toMail(mixed $notifiable): ?MailMessage
    {
        $attempt = $this->loadAttempt();
        $programmeName = $attempt->enrollment?->programme?->name ?? 'Certification programme';
        $passed = (bool) $attempt->passed;
        $score = (string) $attempt->score_percent;
        $passMark = (string) $attempt->pass_mark_percent;

        $mail = (new MailMessage)
            ->subject($passed
                ? 'MarcatursHub: Assessment result — you passed'
                : 'MarcatursHub: Assessment result — not passed')
            ->line('Your certification assessment has been evaluated and your result is available.')
            ->line('Programme: '.$programmeName)
            ->line('Result: '.($passed ? 'Passed' : 'Not passed'))
            ->line('Score: '.$score.'% (pass mark '.$passMark.'%)')
            ->line('Attempt: #'.$attempt->attempt_number);

        if ($passed) {
            $mail->line('Continue in your account to view your award and certificate when available.');
        } else {
            $mail->line('You may review your result and retake the assessment when eligible — no additional certification payment is required.');
        }

        return $mail;
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        $attempt = $this->loadAttempt();
        $enrollment = $attempt->enrollment;
        $version = $enrollment?->programmeVersion;

        return [
            'attempt_id' => $attempt->id,
            'enrollment_id' => $attempt->enrollment_id,
            'assessment_id' => $attempt->assessment_id,
            'programme_id' => $enrollment?->programme_id,
            'programme_version_id' => $attempt->programme_version_id,
            'programme_name' => $enrollment?->programme?->name,
            'programme_version_number' => $version?->version_number,
            'attempt_number' => $attempt->attempt_number,
            'passed' => (bool) $attempt->passed,
            'result' => $attempt->passed ? 'passed' : 'failed',
            'score_percent' => (string) $attempt->score_percent,
            'pass_mark_percent' => (string) $attempt->pass_mark_percent,
            'submitted_at' => $attempt->submitted_at?->toIso8601String(),
        ];
    }

    private function loadAttempt(): CertificationAssessmentAttempt
    {
        return CertificationAssessmentAttempt::query()
            ->with(['enrollment.programme', 'enrollment.programmeVersion'])
            ->findOrFail($this->attemptId);
    }
}
