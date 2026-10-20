# Frontend deployment (ENG-040, aligned in MH-OPS-006)

The Participant and Admin SPAs are Vite/React static builds on **Cloudflare Pages**. They are separate repositories and separate Pages projects. Instructions below were checked against the clean checkouts `frontend-dev-main` and `adminControl-dev-main` (MH-OPS-103 / MH-OPS-105). Those local HEADs were not freshly compared with remote `main`. In this workspace, `frontend/` and `adminControl/` are protected dirty trees; do not take workflow files from them.

| App | Production host | Pages project observed in Cloudflare (MH-OPS-104) | Pages host | How the workflow selects the project |
| --- | --- | --- | --- | --- |
| Participant | `https://app.marcaturshub.com.ng` | `marcaturshub-app` | `marcaturshub-app.pages.dev` | Reads this repo’s `CF_PAGES_PROJECT`. The GitHub value was not inspected |
| Admin | `https://admin.marcaturshub.com.ng` | `marcaturshub-admin` | `marcaturshub-admin.pages.dev` | Reads the Admin repo’s own `CF_PAGES_PROJECT`. That GitHub value was not inspected |

MH-OPS-104 also recorded the production deployment aliases then showing: `6dcae7ca.marcaturshub-app.pages.dev` (description text “MH-OPS-055, PR #8”) and `0cfca432.marcaturshub-admin.pages.dev` (description text “MH-SEC-002, PR #4”). Those aliases are dashboard observations. They are not Git commit SHAs, and a later deploy can replace them. Both projects showed “No Git connection.”

The workflows still pass `--project-name=${{ vars.CF_PAGES_PROJECT }}`. The names above were seen in Cloudflare. They were not read from the GitHub environment variables. Do not hardcode the project name into the workflow.

Node: 22 in CI (Vite requires `^20.19.0 || >=22.12.0`). Build output: `dist/`.

## Pipeline

Reviewed files: `frontend-dev-main/.github/workflows/production.yml`, `frontend-dev-main/.github/workflows/checks.yml`, `adminControl-dev-main/.github/workflows/production.yml`, and `adminControl-dev-main/.github/workflows/checks.yml`.

1. A pull request runs `checks.yml` only (`npm ci`, typecheck, lint, test, build). `production.yml` does not run for pull requests.
2. A push to `main` runs `production.yml`, which calls the same Checks job and skips Deploy.
3. Production publish is a manual **Run workflow** on `main` with input `deploy` set to true. The Deploy job then uses GitHub Environment `production`, builds with the guarded `VITE_*` values, and runs `wrangler pages deploy dist --project-name=<CF_PAGES_PROJECT> --branch=main`.

A push or a pull request does not publish production. `develop` does not deploy to production. Who may dispatch the workflow or approve the `production` environment was not re-checked in MH-OPS-103.

## Required public build-time variables

Values: each repo’s `.env.production.example` (clean checkouts `frontend-dev-main` and `adminControl-dev-main`). Full contract in [production-environment.md](production-environment.md).

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

Admin publish uses the same gate as the Participant workflow: manual Production dispatch on `main`, `deploy=true`, and Environment `production`, with the Admin repository’s own `CF_PAGES_PROJECT`. Admin does not use `VITE_PUBLIC_ORIGIN` or the `VITE_REVERB_*` variables.

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
