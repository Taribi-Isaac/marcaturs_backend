# Architecture — ENG-040 (aligned in MH-OPS-006)

The approved first-production architecture is the **lean pilot** (MH-OPS-003/004, confirmed in MH-OPS-006): a single AWS Lightsail application host behind Cloudflare DNS, sized for a pilot of up to ~500 users. The TAD §9 AWS topology (ECS/ALB/RDS/ElastiCache/CloudFront) is **preserved as the future scale path** below, not replaced (TAD §98 "modest first production").

## Pilot topology (approved)

```text
marcaturshub.com.ng  (Cloudflare DNS)
  ├── marcaturshub.com.ng, www   → Cloudflare redirect → app (Product to confirm)
  ├── app.marcaturshub.com.ng    → Cloudflare Pages  (Participant SPA)
  ├── admin.marcaturshub.com.ng  → Cloudflare Pages  (Admin SPA)
  ├── api.marcaturshub.com.ng    → Lightsail static IP (DNS-only)
  └── ws.marcaturshub.com.ng     → Lightsail static IP (DNS-only)

Lightsail instance (eu-north-1, Ubuntu 24.04 LTS)
  ├── Nginx :443 (Let's Encrypt TLS)
  │     ├── api.*  → PHP-FPM 8.4 → Laravel API
  │     └── ws.*   → 127.0.0.1:8080 → Laravel Reverb
  ├── Redis 7 (127.0.0.1, requirepass) — cache, sessions, queue
  └── Supervisor
        ├── php artisan queue:work redis   (1 worker)
        ├── php artisan schedule:work       (exactly 1)
        └── php artisan reverb:start        (1, loopback)

Lightsail Managed MySQL 8 (same region, private, automated backups + PITR)
Amazon S3 (two private buckets: sensitive, campaign-media)
Resend (transactional email, API transport)
Paystack (platform purchases only)
GitHub Actions (CI + approval-gated deploys from main)
```

### Component decisions

| Concern | Pilot | Future scale (deferred) |
| --- | --- | --- |
| DNS / edge | Cloudflare (free) | Cloudflare and/or Route 53 |
| SPA hosting | Cloudflare Pages (Direct Upload) | Cloudflare Pages or S3 + CloudFront |
| API / workers / Reverb | One Lightsail instance, Supervisor | ECS/Fargate services behind an ALB |
| MySQL | Lightsail Managed MySQL | Amazon RDS MySQL |
| Redis | On the app host (loopback) | ElastiCache |
| Object storage | Private S3 | Private S3 + KMS |
| Email | Resend | Resend |
| Secrets | `shared/.env` on the host (0640) | AWS Secrets Manager |
| Payments | Paystack (platform purchases) | Same |
| CI/CD | GitHub Actions → SSH release / `wrangler pages deploy` | GitHub Actions → ECR/ECS (`staging.yml`, deferred) |

`php artisan serve` is never used in production. Amazon SES is not part of the production path.

### Why `api` and `ws` stay DNS-only initially

Long-lived WebSockets and TLS at Nginx are simplest without the Cloudflare proxy. If either host is later proxied (orange cloud), Nginx must restore the real client IP (`real_ip_header CF-Connecting-IP` with Cloudflare ranges) because Laravel trusts forwarded headers and rate limits key on client IP.

## Process separation

| Process | How it runs | Count |
| --- | --- | --- |
| HTTP API | Nginx → PHP-FPM 8.4 (`/run/php/php8.4-fpm.sock`) | FPM pool |
| Queue worker | Supervisor `queue:work redis --sleep=1 --tries=3 --timeout=60 --max-time=3600` | 1 |
| Scheduler | Supervisor `schedule:work` | exactly 1 (no cron `schedule:run`) |
| Reverb | Supervisor `reverb:start --host=127.0.0.1 --port=8080` | 1 |

Reference configs: `infra/lightsail/` (Nginx, Supervisor, PHP, Redis, `deploy.sh`).

## Scheduled jobs (application)

Registered in `bootstrap/app.php`:

- `campaigns:process-lifecycle` — every 15 minutes
- `commissions:process-overdue` — every 15 minutes
- `commissions:process-reminders` — every 15 minutes

## Environment separation

| Env | Branch | `APP_ENV` | `APP_DEBUG` | Status |
| --- | --- | --- | --- | --- |
| Local | any | `local` | may be `true` | Engineer machines |
| Production | `main` | `production` | **`false`** | Pilot target (this document) |
| Staging | `develop` | `staging` | **`false`** | Future — not built; will mirror the pilot model with separate resources |

Production data must never be casually copied into development (TAD §8).

## Secrets

- Never commit real credentials. `backend/.env.production.example` holds placeholders only.
- Production secrets live in `/var/www/marcaturshub/shared/.env` (owner `deploy:www-data`, mode 0640) and in GitHub Environment `production` secrets for CI.
- `VITE_*` values are public to browsers — never put Paystack secrets, DB passwords, Resend keys or AWS keys in frontend env.

## Future scale path (deferred)

Move toward ECS + ALB + RDS + ElastiCache when **observed** load requires it, for example: sustained memory pressure on the largest sensible Lightsail plan, a need for more than one API instance, queue lag that one host cannot absorb, or availability requirements beyond single-host RTO. The existing container images (`docker/Dockerfile`), `docker-compose.staging.yml`, `infra/aws/staging/` and the `staging.yml` workflows remain as the starting point for that migration.
