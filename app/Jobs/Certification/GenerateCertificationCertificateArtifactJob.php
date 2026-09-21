<?php

namespace App\Jobs\Certification;

use App\Services\Certification\CertificationCertificateArtifactService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateCertificationCertificateArtifactJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $certificateId,
    ) {}

    public function handle(CertificationCertificateArtifactService $artifacts): void
    {
        $artifacts->generateForCertificateId($this->certificateId);
    }

    public function failed(?Throwable $exception): void
    {
        unset($exception);

        app(CertificationCertificateArtifactService::class)
            ->markFailedRetryable($this->certificateId, 'generation_failed');
    }
}
