# Application security headers & Reverb origins (MH-BE-050)

Closes **SEC-005** (Reverb wildcard origins) and **SEC-006** (missing security headers) from MH-BE-045 / MH-GATE-009.

## Reverb allowed origins (SEC-005)

Configuration: `REVERB_ALLOWED_ORIGINS` (comma-separated) → `config('reverb.apps.apps.0.allowed_origins')` via `App\Support\Security\ReverbAllowedOrigins`.

Laravel Reverb matches the WebSocket `Origin` **host** (not scheme/port). Entries may be:

* hosts: `localhost`, `app.example.com`
* host patterns: `*.example.com`
* full origins: `http://localhost:5180` (normalized to `localhost`)

### Rules

| Environment | Unset / empty / only `*` | Explicit list |
| --- | --- | --- |
| `local` / `testing` | Fallback: `localhost`, `127.0.0.1`, `::1`, plus hosts from `FRONTEND_URL` / `ADMIN_URL` | Used (after normalize; `*` stripped) |
| `staging` / `production` / other | **Fail closed** → empty allowlist | Used (after normalize; `*` stripped) |

Wildcard `*` is **never** kept. Empty production allowlist rejects browser WebSocket origins until configured.

Channel authorization is unchanged: only conversation participants may authorize `conversation.{id}`. Origin allowlisting is an additional browser handshake control.

## Security headers (SEC-006)

Middleware: `App\Http\Middleware\SetSecurityHeaders` (appended globally).

| Header | Value | Notes |
| --- | --- | --- |
| `X-Content-Type-Options` | `nosniff` | Always |
| `X-Frame-Options` | `DENY` | API/JSON must not be framed |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Always |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), payment=()` | Baseline lockdown |
| `Strict-Transport-Security` | `max-age=…; includeSubDomains` | Only when `SECURITY_HSTS_ENABLED=true` **and** request is HTTPS |
| `Content-Security-Policy` | **Not set** | Deferred — Vite, Paystack, Reverb, and SPA hosts need edge-aware CSP |
| `X-Powered-By` | Removed when present on the Symfony response | PHP/SAPI may still emit; strip at web server/edge in staging/prod |

Config: `config/security.php`. CORS is **unchanged** (`CORS_ALLOWED_ORIGINS`).

## Local verification

```bash
curl -sI http://127.0.0.1:8000/api/v1/health
# Expect: X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy
# Expect: no Strict-Transport-Security on HTTP localhost
```

Live Reverb origin rejection requires `php artisan reverb:start` with a non-allowlisted Origin; automated tests cover config + host matching without a running Reverb process.
