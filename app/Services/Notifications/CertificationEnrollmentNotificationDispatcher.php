<?php

namespace App\Services\Notifications;

use App\Models\CertificationEnrollment;
use App\Models\User;
use App\Notifications\CertificationEnrollmentActivatedNotification;
use Illuminate\Support\Facades\Log;

class CertificationEnrollmentNotificationDispatcher
{
    public function notifyActivated(CertificationEnrollment $enrollment): void
    {
        $recipient = User::query()->find($enrollment->user_id);

        if ($recipient === null) {
            Log::warning('Certification enrollment notification skipped: recipient missing', [
                'enrollment_id' => $enrollment->id,
                'user_id' => $enrollment->user_id,
            ]);

            return;
        }

        $recipient->notify(new CertificationEnrollmentActivatedNotification(
            $enrollment->id,
            $recipient->id,
        ));
    }
}
