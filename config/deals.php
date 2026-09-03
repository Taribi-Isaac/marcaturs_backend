<?php

return [

    /*
     | Payment evidence files (TAD §22 / §35). Disk may later point at private S3.
     | MIME/size follow the established sensitive-upload convention (MH-BE-004),
     | because UX §17 requires restrictions but does not publish a product constant.
     */
    'payment_evidence_disk' => env('DEAL_PAYMENT_EVIDENCE_DISK', 'sensitive'),

    'max_payment_evidence_kilobytes' => (int) env('DEAL_PAYMENT_EVIDENCE_MAX_KB', 5120),

    'allowed_payment_evidence_mimes' => [
        'pdf',
        'jpeg',
        'jpg',
        'png',
        'webp',
    ],

    /*
     | Null means no automated purge. Retention is pending legal/compliance review.
     */
    'payment_evidence_retention_days' => env('DEAL_PAYMENT_EVIDENCE_RETENTION_DAYS') !== null
        && env('DEAL_PAYMENT_EVIDENCE_RETENTION_DAYS') !== ''
            ? (int) env('DEAL_PAYMENT_EVIDENCE_RETENTION_DAYS')
            : null,

    /*
     | Platform ceiling for Business → Ambassador commission payment (SoT §7.2).
     | A published Campaign Version deadline may be stricter (fewer days), never looser.
     */
    'commission_payment_ceiling_days' => (int) env('DEAL_COMMISSION_PAYMENT_CEILING_DAYS', 7),

];
