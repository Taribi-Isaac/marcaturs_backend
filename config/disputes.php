<?php

return [

    /*
     | Dispute attachment files. MIME/size follow the MH-BE-004 sensitive-upload
     | convention used by PaymentEvidence (MH-BE-025C / MH-BE-025D).
     */
    'attachment_disk' => env('DISPUTE_ATTACHMENT_DISK', 'sensitive'),

    'max_attachment_kilobytes' => (int) env('DISPUTE_ATTACHMENT_MAX_KB', 5120),

    'allowed_attachment_mimes' => [
        'pdf',
        'jpeg',
        'jpg',
        'png',
        'webp',
    ],

];
