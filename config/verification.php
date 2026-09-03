<?php

return [

    'evidence_disk' => env('VERIFICATION_EVIDENCE_DISK', 'sensitive'),

    'max_evidence_kilobytes' => (int) env('VERIFICATION_MAX_EVIDENCE_KB', 5120),

    'allowed_evidence_mimes' => [
        'pdf',
        'jpeg',
        'jpg',
        'png',
        'webp',
    ],

    /*
     * Evidence retention in days. Null means no automated purge is configured.
     * Final retention policy is pending external legal/compliance review.
     */
    'retention_days' => env('VERIFICATION_RETENTION_DAYS') !== null && env('VERIFICATION_RETENTION_DAYS') !== ''
        ? (int) env('VERIFICATION_RETENTION_DAYS')
        : null,

];
