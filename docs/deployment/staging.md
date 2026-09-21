# Staging environment bootstrap (ENG-040 / MH-OPS-001)

**Status:** In-repo deployment foundation is in place. **Live AWS staging is not provisioned** in this engineering environment (no AWS CLI, Docker, GitHub auth, or domain ownership). See [External access required](#external-access-required).

## Goals

Staging must support integrated UAT of:

- Laravel `/api/v1/*`
- Participant Frontend + Admin Frontend
- MySQL, Redis (cache/queue/session/rate limits)
- Private S3-backed evidence + campaign media
- Queue workers + scheduler
- Reverb private chat channels (`conversation.{id}`)
- Paystack **test** mode only
- Controlled transactional email
- Health/smoke + security headers (MH-BE-050)

**Not in scope until staging GREEN:** production deploy, production DNS, production Paystack live keys, production data.

## Repository artifacts

| Path | Role |
| ---- | ---- |
| `docker/Dockerfile` | Multi-target images (`api` / `worker` / `scheduler` / `reverb`) |
| `docker/entrypoint.sh` | Optional migrate + config/route/event cache |
| `docker-compose.staging.yml` | Local parity stack (requires Docker) |
| `.env.staging.example` | Staging env template (no secrets) |
| `.github/workflows/staging.yml` | Test → build images → gated deploy |
| `scripts/staging-smoke.sh` | Health + headers smoke |
| `infra/aws/staging/` | CloudFormation scaffold (parameters only; apply with AWS access) |
| `docs/deployment/*` | Runbooks |

## Environment variables

Copy `.env.staging.example` into Secrets Manager / untracked `.env.staging`.

| Variable | Staging expectation |
| -------- | ------------------- |
| `APP_ENV` | `staging` |
| `APP_DEBUG` | `false` |
| `SESSION_SECURE_COOKIE` | `true` (HTTPS) |
| `CORS_ALLOWED_ORIGINS` | Explicit FE + Admin origins (no `*`) |
| `REVERB_ALLOWED_ORIGINS` | Explicit FE origins (no `*`; empty = fail-closed) |
| `PAYSTACK_*` | **Test** keys only |
| `SENSITIVE_DISK_DRIVER` / `CAMPAIGN_MEDIA_DISK_DRIVER` | `s3` + private buckets |
| `MAIL_MAILER` | Staging SMTP/SES (not `log` for email UAT) |
| `SECURITY_HSTS_ENABLED` | `true` behind HTTPS |
| `RUN_MIGRATIONS` | `true` only on controlled single release task |

Never commit filled `.env.staging`.

## Migrations

```bash
php artisan migrate --force
```

Do **not** use `migrate:fresh` on persistent staging.

## Queue / scheduler

Separate ECS (or Compose) tasks:

```bash
php artisan queue:work redis --sleep=1 --tries=3 --max-time=3600
php artisan schedule:work
```

Scheduled commands: `campaigns:process-lifecycle`, `commissions:process-overdue`, `commissions:process-reminders` (every 15 minutes).

## Reverb

Persist-first chat unchanged: DB commit → broadcast on `private-conversation.{id}`. Auth: `POST /api/broadcasting/auth` with Sanctum + `account.access`.

```env
REVERB_ALLOWED_ORIGINS=https://app.staging.example.com,https://admin.staging.example.com
```

Replace example hosts with **actual** staging hosts when Product/Ops supplies them. Do not invent production domains.

## Frontends

See [frontend.md](frontend.md). Build with `.env.staging.example` templates under `frontend/` and `adminControl/`.

## Health / smoke

```bash
STAGING_API_URL=https://<actual-staging-api-host> ./scripts/staging-smoke.sh
```

Optional: `STAGING_APP_URL`, `STAGING_ADMIN_URL` for static shell checks.

## Security checklist (staging)

- [ ] `APP_DEBUG=false`
- [ ] HTTPS / ACM
- [ ] Secrets Manager (not Git)
- [ ] Paystack test keys only
- [ ] Private S3 (Block Public Access)
- [ ] Explicit CORS + Reverb origins
- [ ] HSTS (app and/or edge)
- [ ] Security headers on `/api/v1/health`
- [ ] RDS/Redis private
- [ ] Sanctum stateful domains match FE hosts
- [ ] No production credentials in staging

## Rollback

See [rollback.md](rollback.md).

## External access required

To make staging **reachable**, Ops must provide:

1. AWS account + IAM (ECR, ECS, RDS, ElastiCache, S3, Secrets Manager, ACM, Route 53, CloudWatch)
2. Real domain names for `api` / `app` / `admin` / `ws` staging hosts
3. ACM certificates
4. Paystack **test** keys
5. Staging SMTP/SES identity
6. GitHub Environment `staging` with OIDC → AWS role
7. Docker (CI runners or engineer machines for Compose parity)

Until then: images/workflows/docs can be maintained, but **live deploy remains blocked**. The `deploy-staging` job fails closed without AWS.

## Local parity (optional)

```bash
cp .env.staging.example .env.staging
# fill local passwords / APP_KEY — never production secrets
docker compose -f docker-compose.staging.yml --env-file .env.staging up --build
curl -fsS http://127.0.0.1:18000/api/v1/health
```

This is **not** AWS staging. Docker was unavailable on the ENG-040 agent host.

## Verification matrix (once staging URL exists)

| Check | Method |
| ----- | ------ |
| Health + headers | `staging-smoke.sh` |
| Auth | login/logout/`/me` Business, Ambassador, Admin |
| Commerce | Campaign → Version → Marketplace → Deal → Evidence → Confirm → Commission (no real customer money) |
| Cover / resources | upload + authorized download |
| Queue | trigger notification; worker processes job |
| Scheduler | lifecycle/reminder logs after ≤15m |
| Reverb | conversation; live `message.created` |
| Paystack | extension/Featured/certification **test** initialize only |
| FE | Participant + Admin against staging API |
| Storage | controlled private upload/download |

## Production

See [production.md](production.md). **NOT READY** until this matrix is GREEN on live staging.
