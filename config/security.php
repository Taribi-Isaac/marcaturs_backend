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
    | (prefer the Cloudflare edge in production).
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

    /*
    |--------------------------------------------------------------------------
    | Admin origin binding (Option A) + session-only Admin API (MH-SEC-012)
    |--------------------------------------------------------------------------
    |
    | Protected /api/v1/admin/* requests require a first-party browser session
    | (Sanctum TransientToken). Bearer/PAT credentials are rejected regardless
    | of Origin. Cookie-authenticated requests must also carry one of these
    | exact Origin values (EnsureAdminOrigin). Defaults to ADMIN_FRONTEND_URL.
    | Empty in production rejects every browser Admin request.
    |
    */

    'admin_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ADMIN_ALLOWED_ORIGINS', env('ADMIN_FRONTEND_URL', ''))),
    ))),

];
