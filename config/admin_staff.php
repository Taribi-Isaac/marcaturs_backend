<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Admin Staff RBAC (MH-BE-045)
    |--------------------------------------------------------------------------
    */
    'invitation_ttl_hours' => (int) env('ADMIN_STAFF_INVITATION_TTL_HOURS', 72),

    'admin_frontend_url' => env('ADMIN_FRONTEND_URL', env('FRONTEND_URL', 'http://localhost:5174')),
];
