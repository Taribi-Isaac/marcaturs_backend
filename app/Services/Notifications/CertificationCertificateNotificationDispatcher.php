<?php

namespace App\Services\Notifications;

use App\Models\CertificationCertificate;
use App\Models\User;
use App\Notifications\CertificationCertificateAvailableNotification;
use Illuminate\Support\Facades\Log;

class CertificationCertificateNotificationDispatcher
{
    public function notifyAvailable(CertificationCertificate $certificate): void
    {
        $certificate->loadMissing('award');
        $recipient = User::query()->find($certificate->award?->user_id);

        if ($recipient === null) {
            Log::warning('Certification certificate notification skipped: recipient missing', [
                'certificate_id' => $certificate->id,
                'award_id' => $certificate->award_id,
            ]);

            return;
        }

        $recipient->notify(new CertificationCertificateAvailableNotification(
            $certificate->id,
            $recipient->id,
        ));
    }
}
