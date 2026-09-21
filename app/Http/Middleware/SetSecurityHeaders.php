<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline HTTP security headers (MH-BE-050 / SEC-006).
 *
 * Intentionally omits a full Content-Security-Policy (Vite, Paystack checkout,
 * Reverb, and SPA assets need an edge-aware CSP). HSTS is opt-in via config and
 * only emitted on HTTPS requests so localhost HTTP is never HSTS-pinned.
 */
class SetSecurityHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $headers = config('security.headers', []);

        $response->headers->set(
            'X-Content-Type-Options',
            (string) ($headers['x_content_type_options'] ?? 'nosniff'),
        );
        $response->headers->set(
            'X-Frame-Options',
            (string) ($headers['x_frame_options'] ?? 'DENY'),
        );
        $response->headers->set(
            'Referrer-Policy',
            (string) ($headers['referrer_policy'] ?? 'strict-origin-when-cross-origin'),
        );
        $response->headers->set(
            'Permissions-Policy',
            (string) ($headers['permissions_policy'] ?? 'camera=(), microphone=(), geolocation=()'),
        );

        if ($this->shouldSendHsts($request, $headers)) {
            $maxAge = (int) ($headers['hsts_max_age'] ?? 31_536_000);
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age='.$maxAge.'; includeSubDomains',
            );
        }

        // Best-effort application-layer removal; PHP/SAPI may still emit it.
        $response->headers->remove('X-Powered-By');
        if (! headers_sent() && function_exists('header_remove')) {
            header_remove('X-Powered-By');
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private function shouldSendHsts(Request $request, array $headers): bool
    {
        if (! ($headers['hsts_enabled'] ?? false)) {
            return false;
        }

        return $request->secure();
    }
}
