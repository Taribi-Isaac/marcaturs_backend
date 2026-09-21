<?php

namespace App\Services\Notifications;

use App\Enums\CertificationAssessmentAttemptStatus;
use App\Models\CertificationAssessmentAttempt;
use App\Models\User;
use App\Notifications\CertificationAssessmentResultNotification;
use Illuminate\Support\Facades\Log;

class CertificationAssessmentResultNotificationDispatcher
{
    public function notifyResult(CertificationAssessmentAttempt $attempt): void
    {
        if ($attempt->status !== CertificationAssessmentAttemptStatus::Submitted) {
            Log::warning('Certification assessment result notification skipped: attempt not submitted', [
                'attempt_id' => $attempt->id,
                'status' => $attempt->status?->value,
            ]);

            return;
        }

        $attempt->loadMissing('enrollment');
        $recipientUserId = $attempt->enrollment?->user_id;
        $recipient = $recipientUserId !== null
            ? User::query()->find($recipientUserId)
            : null;

        if ($recipient === null) {
            Log::warning('Certification assessment result notification skipped: recipient missing', [
                'attempt_id' => $attempt->id,
                'enrollment_id' => $attempt->enrollment_id,
                'user_id' => $recipientUserId,
            ]);

            return;
        }

        Log::info('Certification assessment result notification dispatched', [
            'attempt_id' => $attempt->id,
            'enrollment_id' => $attempt->enrollment_id,
            'user_id' => $recipient->id,
            'passed' => (bool) $attempt->passed,
            'attempt_number' => $attempt->attempt_number,
        ]);

        $recipient->notify(new CertificationAssessmentResultNotification(
            $attempt->id,
            $recipient->id,
        ));
    }
}
