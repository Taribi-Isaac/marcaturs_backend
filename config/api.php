<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public API version
    |--------------------------------------------------------------------------
    |
    | URI versioning as defined in the Technical Architecture Document:
    | /api/v1/...
    |
    */

    'version' => env('API_VERSION', 'v1'),

    /*
    |--------------------------------------------------------------------------
    | Rate limiting
    |--------------------------------------------------------------------------
    |
    | Limits are environment-configurable. Named limiters for authentication
    | and high-risk operations are registered now so later tasks can apply
    | them without changing the limiter architecture.
    |
    */

    'rate_limits' => [
        'api_per_minute' => (int) env('API_RATE_LIMIT_PER_MINUTE', 60),
        'login_per_minute' => (int) env('LOGIN_RATE_LIMIT_PER_MINUTE', 5),
        'registration_per_minute' => (int) env('REGISTRATION_RATE_LIMIT_PER_MINUTE', 5),
        'password_reset_per_minute' => (int) env('PASSWORD_RESET_RATE_LIMIT_PER_MINUTE', 5),
        'email_verification_per_minute' => (int) env('EMAIL_VERIFICATION_RATE_LIMIT_PER_MINUTE', 5),
        'uploads_per_minute' => (int) env('UPLOAD_RATE_LIMIT_PER_MINUTE', 10),
    ],

    'pagination' => [
        'default_per_page' => (int) env('API_PAGINATION_DEFAULT', 15),
        'max_per_page' => (int) env('API_PAGINATION_MAX', 100),
    ],

];
