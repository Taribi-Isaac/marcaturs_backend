<?php

return [

    /*
     | Private disk for certification downloadable lesson resources.
     | Credentials never reach the browser; access is via authorized Admin (and later enrolled Ambassador) endpoints.
     */
    'resource_disk' => env('CERTIFICATION_RESOURCE_DISK', 'sensitive'),

    /*
     | Platform configuration — not a product-document constant.
     */
    'max_resource_kilobytes' => (int) env('CERTIFICATION_RESOURCE_MAX_FILE_KB', 20480),

    /*
     | Display issuer name on issued certificates (Admin/ops configurable via env).
     | Not a legal certificate-wording template — that remains Product/Counsel open.
     */
    'certificate_issuer' => env('CERTIFICATION_CERTIFICATE_ISSUER', 'MarcatursHub'),

    /*
     | Private disk for generated certificate PDF artifacts.
     */
    'certificate_disk' => env('CERTIFICATION_CERTIFICATE_DISK', env('CERTIFICATION_RESOURCE_DISK', 'sensitive')),

];
