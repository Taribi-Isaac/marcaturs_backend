<?php

namespace App\Enums;

enum CertificationCertificateStatus: string
{
    /**
     * Certificate record registered and available in-account.
     * PDF/artifact generation is a separate deferred concern (FR-069).
     */
    case Issued = 'issued';
}
