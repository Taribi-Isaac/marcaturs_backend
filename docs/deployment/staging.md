# Staging environment bootstrap (ENG-040A / MH-OPS-001)

**Status:** Repository deployment foundation is in place. **Live AWS staging was not provisioned** in this environment (no AWS CLI / Docker / account credentials available). See [External access required](#external-access-required).

## Goals

Staging must support integrated UAT of:

- Laravel `/api/v1/*`
- Primary Frontend + Admin Frontend
- MySQL, Redis (cache/queue/session)
- Private S3-backed evidence + campaign media
- Queue workers + scheduler
- Reverb private chat channels
- Paystack **test** mode
- Controlled transactional email
- Health/smoke checks

**Not in scope:** production deploy, production DNS cutover, production Paystack keys, production data.

## Implementation plan (executed in-repo)

1. Document TAD-aligned topology → `architecture.md`
2. Multi-target Docker image (`api` / `worker` / `scheduler` / `reverb`)
3. Optional local staging-parity Compose file
4. `.env.staging.example` (placeholders only)
5. GitHub Actions: test → build images → **manual** deploy gate (AWS stub)
6. Configurable Reverb `allowed_origins` (no forced `*` in staging)
7. S3 drivers for `sensitive` + `campaign_media` disks
8. Smoke script for health once a URL exists
9. Frontend/Admin `.env.staging.example` for build-time API/Reverb URLs

## Repository artifacts

| Path | Role |
| ---- | ---- |
| `docker/Dockerfile` | Multi-target images |
| `docker/entrypoint.sh` | Optional migrate + config cache |
| `docker-compose.staging.yml` | Local parity stack (requires Docker) |
| `.env.staging.example` | Staging env template |
| `.github/workflows/staging.yml` | CI build + gated deploy stub |
| `scripts/staging-smoke.sh` | Health smoke against `STAGING_API_URL` |
| `docs/deployment/*` | This documentation |

## Environment variables

Copy `.env.staging.example` into Secrets Manager / a local untracked `.env.staging`.

Critical staging rules:

| Variable | Staging expectation |
| -------- | ------------------- |
| `APP_ENV` | `staging` |
| `APP_DEBUG` | `false` |
| `SESSION_SECURE_COOKIE` | `true` (HTTPS) |
| `CORS_ALLOWED_ORIGINS` | Explicit FE + Admin origins (no `*`) |
| `REVERB_ALLOWED_ORIGINS` | Explicit FE origins (no `*`) |
| `PAYSTACK_*` | **Test** keys only |
| `SENSITIVE_DISK_DRIVER` / `CAMPAIGN_MEDIA_DISK_DRIVER` | `s3` with private buckets |
| `MAIL_MAILER` | Real staging SMTP/SES (not `log`) |
| `RUN_MIGRATIONS` | `true` only on controlled release task |

Never commit filled `.env.staging`.

## Migrations

```bash
php artisan migrate --force
```

Do **not** use `migrate:fresh` on persistent staging.

Demo seed (`php artisan marcaturs:seed-demo`) remains a guarded UAT tool — not production provisioning. Use only under its existing guard rules if Product wants demo users on staging.

## Queue / scheduler

Staging ECS (or Compose) must run **separate** tasks:

```bash
php artisan queue:work redis --sleep=1 --tries=3 --max-time=3600
php artisan schedule:work
```

Scheduled commands (already registered):

- `campaigns:process-lifecycle` every 15 minutes
- `commissions:process-overdue` every 15 minutes
- `commissions:process-reminders` every 15 minutes

## Reverb / WebSockets

Existing persist-first model unchanged:

```text
POST /messages → DB commit → MessageCreated → private conversation.{id}
```

Staging host suggestion: `wss://ws.staging.example.com` (ALB target group → Reverb service, HTTPS/ACM).

Channel auth remains `POST /api/broadcasting/auth` with Sanctum + `account.access`.

Set:

```env
REVERB_ALLOWED_ORIGINS=https://app.staging.example.com,https://admin.staging.example.com
```

REST message history remains the fallback if WebSockets fail.

## Frontends

Build with staging env files (see Primary FE / Admin `.env.staging.example`):

```bash
# Primary FE
VITE_API_BASE_URL=https://api.staging.example.com/api/v1
VITE_BACKEND_ORIGIN=https://api.staging.example.com
VITE_REVERB_APP_KEY=...
VITE_REVERB_HOST=ws.staging.example.com
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https

# Admin FE
VITE_API_BASE_URL=https://api.staging.example.com/api/v1
VITE_BACKEND_ORIGIN=https://api.staging.example.com
```

Publish static builds to private/public staging S3 + CloudFront (exact bucket names from infra).

## Health / smoke

```bash
curl -fsS "$STAGING_API_URL/api/v1/health"
STAGING_API_URL=https://api.staging.example.com ./scripts/staging-smoke.sh
```

Health reports application/database/cache status without secrets.

## Security checklist (staging)

- [ ] `APP_DEBUG=false`
- [ ] HTTPS / ACM certificates
- [ ] Secrets in Secrets Manager (not Git)
- [ ] Paystack **test** keys only
- [ ] Private S3 (Block Public Access on)
- [ ] Explicit CORS + Reverb origins
- [ ] RDS/Redis not publicly reachable
- [ ] Sanctum stateful domains match FE hosts
- [ ] Rate limiting + `account.access` + `role` middleware unchanged
- [ ] No production credentials in staging tasks

## Rollback approach

1. ECS: redeploy previous task definition revision / ECR image digest.
2. Do not roll back migrations automatically — prepare reverse migrations before risky schema changes.
3. Frontends: CloudFront invalidation to previous S3 object version / prior prefix.

## External access required

To make staging **actually reachable**, Engineering/Ops must provide:

1. AWS account + IAM (ECR, ECS, RDS, ElastiCache, S3, Secrets Manager, ACM, Route 53, CloudWatch)
2. Domain names for `api` / `app` / `admin` / `ws` staging hosts
3. ACM certificates
4. Paystack **test** keys
5. Staging SMTP/SES identity
6. GitHub Environment `staging` with OIDC→AWS role
7. Docker available in CI (GitHub-hosted runners provide this; local engineer machines may not)

Until then: images and workflows can be authored, but **deploy-staging exits with a hard error by design**.

## Local parity (optional)

If Docker is available on an engineer machine:

```bash
cp .env.staging.example .env.staging
# fill local passwords / APP_KEY
docker compose -f docker-compose.staging.yml --env-file .env.staging up --build
curl -fsS http://127.0.0.1:18000/api/v1/health
```

This is **not** AWS staging.

## Verification matrix (once staging URL exists)

| Check | Method |
| ----- | ------ |
| Health | `staging-smoke.sh` |
| Auth | login/logout/`/me` Business, Ambassador, Admin |
| Commerce | Campaign → Version → Marketplace → Deal → Evidence → Confirm → Commission |
| Cover / resources | upload + authorized download |
| Queue | trigger notification; confirm worker processes job |
| Scheduler | observe lifecycle/reminder logs after 15m |
| Reverb | open conversation; receive `message.created` |
| Paystack | extension/Featured **test** initialize only |
| FE | Primary + Admin against staging API |

## Production readiness

**NOT READY** for production deployment. ENG-040A only prepares staging foundation.
