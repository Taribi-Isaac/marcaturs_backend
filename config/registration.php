<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Participant registration mode (MH-OPS-031)
    |--------------------------------------------------------------------------
    |
    | open      — any BUSINESS or AMBASSADOR may self-register (existing behaviour).
    | allowlist — registration succeeds only for an email + role pair approved by
    |             a SUPER_ADMIN via /api/v1/admin/registration-approvals.
    |
    | Any other value is treated as "allowlist" (fail closed).
    |
    */
    'mode' => env('REGISTRATION_MODE', 'open'),
];
