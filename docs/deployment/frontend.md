# Frontend deployment (ENG-040)

Participant SPA (`frontend/`) and Admin SPA (`adminControl/`) are Vite/React static builds.

## Build

```bash
# Participant
cd frontend
cp .env.staging.example .env.staging   # fill non-secret VITE_* only
npm ci
npm run typecheck && npm run lint && npm test && npm run build -- --mode staging

# Admin
cd adminControl
cp .env.staging.example .env.staging
npm ci
npm run typecheck && npm run lint && npm test && npm run build -- --mode staging
```

Publish `dist/` to dedicated staging S3 buckets; attach CloudFront + ACM. Exact bucket/distribution IDs come from AWS provisioning (see `aws-staging-checklist.md`).

## Required public build-time variables

### Participant

| Variable | Purpose |
| -------- | ------- |
| `VITE_API_BASE_URL` | `https://<api-host>/api/v1` |
| `VITE_BACKEND_ORIGIN` | API origin (Sanctum/CSRF) |
| `VITE_PUBLIC_ORIGIN` | Canonical/public origin (MH-FE-023) |
| `VITE_REVERB_APP_KEY` | Public Reverb key |
| `VITE_REVERB_HOST` / `PORT` / `SCHEME` | WSS endpoint |

### Admin

| Variable | Purpose |
| -------- | ------- |
| `VITE_API_BASE_URL` | Same API |
| `VITE_BACKEND_ORIGIN` | Same API origin |

Admin `index.html` ships `noindex,nofollow` (MH-FE-023). Do not add Admin routes to public sitemaps.

## SPA routing

CloudFront (or S3 website) must fall back unknown paths to `index.html` for client-side routes (`/app/*`, `/discover`, etc.).

## Secrets

Never put `PAYSTACK_SECRET_KEY`, `APP_KEY`, DB, or AWS secret keys in `VITE_*`.

## Production

Same process with production origins after staging GREEN — see `production.md`. Do not invent production hostnames.
