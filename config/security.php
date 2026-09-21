<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application security headers (MH-BE-050 / SEC-006)
    |--------------------------------------------------------------------------
    |
    | Baseline response headers applied by SetSecurityHeaders middleware.
    | A full Content-Security-Policy is intentionally not set here — Vite,
    | Paystack, Reverb, and SPA asset hosts need an environment-specific CSP
    | (prefer CloudFront / edge in staging/production).
    |
    | HSTS must not be enabled on plain HTTP localhost. Set
    | SECURITY_HSTS_ENABLED=true only behind HTTPS (staging/production).
    |
    */

    'headers' => [
        'x_content_type_options' => 'nosniff',
        'x_frame_options' => 'DENY',
        'referrer_policy' => 'strict-origin-when-cross-origin',
        'permissions_policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
        'hsts_enabled' => filter_var(env('SECURITY_HSTS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31_536_000),
    ],

];
