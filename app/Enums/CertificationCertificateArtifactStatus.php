<?php

namespace App\Enums;

/**
 * PDF artifact lifecycle for an issued Certificate.
 * Independent of CertificationCertificateStatus (which remains `issued`).
 */
enum CertificationCertificateArtifactStatus: string
{
    case PendingGeneration = 'pending_generation';
    case Generated = 'generated';
    case FailedRetryable = 'failed_retryable';
}
