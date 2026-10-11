# Frontend deployment (ENG-040, aligned in MH-OPS-006)

Participant SPA (`frontend/`) and Admin SPA (`adminControl/`) are Vite/React static builds hosted on **Cloudflare Pages**:

| App | Host | Pages project |
| --- | --- | --- |
| Participant | `https://app.marcaturshub.com.ng` | Direct Upload project (name in GitHub var `CF_PAGES_PROJECT`) |
| Admin | `https://admin.marcaturshub.com.ng` | Separate Direct Upload project |

Node: 22 in CI (Vite requires `^20.19.0 || >=22.12.0`). Build output: `dist/`.

## Pipeline

Each repo has `.github/workflows/production.yml`:

1. Push to `main` / pull request → `npm ci`, `typecheck`, `lint`, `test`, `build`.
2. Manual dispatch with `deploy = true` on `main` → GitHub Environment `production` approval → guarded production build → `wrangler pages deploy dist --project-name=<project> --branch=main`.

The Pages projects use **Direct Upload** (no Cloudflare Git integration) so only approved workflow runs publish. `develop` is never deployed to production.

## Required public build-time variables

Values: `frontend/.env.production.example` and `adminControl/.env.production.example`; full contract in [production-environment.md](production-environment.md).

### Participant

| Variable | Production |
| --- | --- |
| `VITE_API_BASE_URL` | `https://api.marcaturshub.com.ng/api/v1` |
| `VITE_BACKEND_ORIGIN` | `https://api.marcaturshub.com.ng` |
| `VITE_PUBLIC_ORIGIN` | `https://app.marcaturshub.com.ng` |
| `VITE_REVERB_APP_KEY` | Backend `REVERB_APP_KEY` (public) |
| `VITE_REVERB_HOST` / `PORT` / `SCHEME` | `ws.marcaturshub.com.ng` / `443` / `https` |

### Admin

| Variable | Production |
| --- | --- |
| `VITE_API_BASE_URL` | `https://api.marcaturshub.com.ng/api/v1` |
| `VITE_BACKEND_ORIGIN` | `https://api.marcaturshub.com.ng` |

The deploy job fails if a variable is missing, uses `http://`, or points at `localhost`, `127.0.0.1` or `example.com`, and it greps the bundle for development API origins. Without `VITE_API_BASE_URL` the apps would call `/api/v1` on the Pages host, which cannot work, so the guard is mandatory.

Admin `index.html` ships `noindex,nofollow` (MH-FE-023). Do not add Admin routes to public sitemaps.

## SPA routing

Cloudflare Pages serves `index.html` for unknown paths when the build has no top-level `404.html` (current builds have none), so client routes such as `/app/*`, `/discover` and `/pay/<token>` work without a `_redirects` file.

## Auth across subdomains

The SPAs call `api.marcaturshub.com.ng` with `credentials: 'include'`. This relies on backend `SESSION_DOMAIN=.marcaturshub.com.ng` (so the SPA can read `XSRF-TOKEN`), `SANCTUM_STATEFUL_DOMAINS` containing both SPA hosts, and `CORS_ALLOWED_ORIGINS` listing both exact origins.

## Secrets

Never put `PAYSTACK_SECRET_KEY`, `APP_KEY`, `RESEND_API_KEY`, `REVERB_APP_SECRET`, DB or AWS keys in `VITE_*`.

## Deferred

S3 + CloudFront hosting (`staging.yml` in each SPA repo, manual dispatch only) remains a future option and is not used by the pilot. Edge security headers / CSP for the SPAs (`_headers`) are deferred to the Cloudflare configuration task.
