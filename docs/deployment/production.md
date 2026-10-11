# Production runbook — Lightsail pilot (ENG-040 / MH-OPS-006)

Production is `main` → `marcaturshub.com.ng`. This document defines **how** production is built and released. It does not authorize provisioning or go-live; those follow `production-checklist.md` and require Product/Engineering approval.

Related: [architecture.md](architecture.md) · [production-environment.md](production-environment.md) · [production-checklist.md](production-checklist.md) · [frontend.md](frontend.md) · [rollback.md](rollback.md)

## 1. Host baseline

| Item | Value |
| --- | --- |
| Instance | Lightsail Linux, **2 GB RAM / 2 vCPU / 60 GB** (≈$12/month) in `eu-north-1`; 4 GB is the first upgrade step |
| OS | Ubuntu 24.04 LTS |
| Static IP | Attached (DNS for `api` and `ws` points here) |
| Firewall (Lightsail) | 80, 443, and 22 for the GitHub-hosted deploy runners (their IPs cannot be allow-listed). SSH is key-only, no root, `AllowUsers ubuntu deploy`, fail2ban on `sshd`; `deploy` may only `sudo` the two release commands. **Never** 6379, 3306, 8080 |
| Swap | 1–2 GB emergency swap |
| PHP | 8.4 from the `ondrej/php` PPA (Ubuntu 24.04 ships 8.3) |
| PHP packages | `php8.4-fpm php8.4-cli php8.4-mysql php8.4-redis php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-bcmath php8.4-intl php8.4-opcache` |
| Other packages | `nginx redis-server supervisor certbot mysql-client unzip acl fail2ban` |
| Composer / Node | Not needed on the host — CI builds `vendor/` and the SPAs |

### Users and layout

```text
deploy (SSH user for CI, member of www-data; sudo only for the two commands below)
/var/www/marcaturshub/
  bin/deploy.sh                 installed by CI from infra/lightsail/deploy.sh
  releases/<YYYYmmddHHMMSS-sha>/
  failed/<id>.<UTC>.<reason>/   0750, created by deploy.sh — failed / rolled-back releases (manual cleanup)
  state/                        0750, created by deploy.sh — active, history.log, lock/
  shared/.env                   0640 deploy:www-data — production secrets
  shared/storage/               2775 deploy:www-data (setgid) — logs, framework cache
  current -> releases/<id>
/var/log/marcaturshub/          Supervisor program logs
```

Create `shared/storage/{app/private,framework/cache,framework/sessions,framework/views,logs}` once. Uploaded files live in S3, not on the host.

`/etc/sudoers.d/marcaturshub-deploy`:

```text
deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.4-fpm, /usr/bin/supervisorctl restart marcaturshub\:*
```

### Reference configuration (`infra/lightsail/`)

| File | Install to |
| --- | --- |
| `nginx/api.marcaturshub.com.ng.conf` | `/etc/nginx/sites-available/` (+ symlink in `sites-enabled/`) |
| `nginx/ws.marcaturshub.com.ng.conf` | `/etc/nginx/sites-available/` |
| `supervisor/marcaturshub.conf` | `/etc/supervisor/conf.d/` |
| `php/99-marcaturshub.ini` | `/etc/php/8.4/fpm/conf.d/` and `/etc/php/8.4/cli/conf.d/` |
| `redis/marcaturshub.conf` | merge into `/etc/redis/redis.conf` |
| `deploy.sh` | installed by the workflow to `/var/www/marcaturshub/bin/deploy.sh` |

TLS: `certbot certonly --webroot -w /var/www/letsencrypt -d api.marcaturshub.com.ng -d ws.marcaturshub.com.ng --deploy-hook "systemctl reload nginx"` (the reference Nginx files already contain the TLS directives, so certbot must not edit them; auto-renewal via the certbot systemd timer).

### PHP-FPM pool (2 GB host)

In `/etc/php/8.4/fpm/pool.d/www.conf`: `pm = dynamic`, `pm.max_children = 8`, `pm.start_servers = 2`, `pm.min_spare_servers = 2`, `pm.max_spare_servers = 4`, `pm.max_requests = 500`.

Approximate memory budget: FPM 8 × ~70 MB, queue ~80 MB, scheduler ~60 MB, Reverb ~100 MB, Redis ≤256 MB, Nginx + OS ~350 MB → ~1.4 GB. Move to the 4 GB plan if memory stays above ~80% or swap is used steadily.

## 2. Process model

```text
Internet → Nginx :443 → PHP-FPM 8.4 → Laravel          (api.marcaturshub.com.ng)
Internet → Nginx :443 → 127.0.0.1:8080 → Reverb         (wss://ws.marcaturshub.com.ng)
Supervisor → php artisan queue:work redis               (1 worker)
Supervisor → php artisan schedule:work                  (exactly 1 — no cron schedule:run)
Supervisor → php artisan reverb:start --host=127.0.0.1  (1)
```

Notifications and the certificate PDF job are queued; without the worker no email is sent. Chat broadcasts are synchronous after commit (`ShouldBroadcastNow`) and do not need the worker. Laravel publishes events to Reverb through `REVERB_HOST:443`, so the `ws` Nginx site must proxy every path, not just the WebSocket endpoint.

## 3. Release pipeline

Workflow: `.github/workflows/production.yml` (backend), `frontend/.github/workflows/production.yml`, `adminControl/.github/workflows/production.yml`.

```text
pull request to main → backend-checks.yml (the same Pint + PHPUnit; no secrets, no environment, cannot deploy)
merge to main → tests (Pint + PHPUnit / typecheck + lint + vitest) → build artifact
manual "Run workflow" (deploy = true) on main
  → GitHub Environment "production" approval (required reviewer)
  → API: upload artifact → deploy.sh release [--migrate] → smoke
         → if the new release became current and a later step failed: deploy.sh rollback → smoke again
         → job summary: active release, deploy and rollback outcome
  → SPAs: guarded production build → wrangler pages deploy (Direct Upload)
```

`develop` never triggers production jobs. Deploy jobs refuse to run from any ref other than `main`.

### What `deploy.sh release` does

1. Takes `state/lock` (a concurrent release or rollback exits 75) and validates the release id.
2. Refuses to run unless `shared/.env` has `APP_ENV=production` and `APP_DEBUG=false`, and `current` (if any) points into `releases/`.
3. Unpacks the artifact into `releases/<id>`, links `shared/.env` and `shared/storage`. A leftover `releases/<id>` from an attempt that never activated is moved to `failed/`; an id that was ever active is refused.
4. `php artisan config:cache`, `route:cache`, `event:cache`.
5. **Migration gate:** only with `--migrate` (workflow input `migrate`, which also requires `backup_confirmed`). Prints pending migrations, then `php artisan migrate --force`. On an empty database (first release) `migrate:status` reports no migrations table; the script says so and continues only when no release is active. Any other `migrate:status` error (connection, permissions) stops the release. Migrations run before the switch, so they must be backward compatible with the running release (TAD §69). Never `migrate:fresh`, `db:wipe` or seeders in production.
6. Atomically switches `current`, reloads PHP-FPM (clears OPcache) and checks it is active, restarts `marcaturshub:*` (queue, scheduler, Reverb), and runs the health check (`HEALTH_URL`, supplied by the workflow via Nginx on `127.0.0.1`).
7. Marks the release activated and records it in `state/active` with the previous activated release as rollback target.
8. Keeps the five newest releases (never the current, recorded active or recorded previous release).

Failures before step 6's switch exit 10 and leave the old release serving; failures after it exit 20 and leave the new release current for rollback. Exit codes, `deploy.sh status` and recovery: [rollback.md](rollback.md).

### Before running a migration release

1. Take a manual Lightsail database snapshot (or note the PITR timestamp) and confirm it completed.
2. Read the pending migrations; anything destructive needs separate review (TAD §69).
3. Run the workflow with `migrate = true` and `backup_confirmed = true`.

### First release (one-off)

1. Populate `shared/.env` from `.env.production.example`; generate `APP_KEY` (`php artisan key:generate --show` on any machine) and Reverb app id/key/secret (random strings).
2. Run the workflow with `migrate = true` against the empty database. The release log says "No migrations table yet … expected only for the first release" before migrating.
3. There is no previous release to roll back to. If the first release fails after activation, the workflow reports "FAILED (FIRST RELEASE) … Manual recovery required" and leaves it in place; follow "First release has no rollback target" in [rollback.md](rollback.md).
4. Create the first admin with `php artisan marcaturs:create-admin` from `current/` (never seed demo data; `DevelopmentSeedGuard` blocks it outside local).

## 4. Smoke

`scripts/smoke.sh` runs automatically after a release and can be run by hand:

```bash
API_URL=https://api.marcaturshub.com.ng \
APP_URL=https://app.marcaturshub.com.ng \
ADMIN_URL=https://admin.marcaturshub.com.ng \
WS_URL=wss://ws.marcaturshub.com.ng REVERB_APP_KEY=<public key> \
EXPECT_HSTS=1 ./scripts/smoke.sh
```

It checks health (database), security headers + HSTS, no `X-Powered-By`, `/up`, both SPA shells, CORS allows the app origin with credentials and rejects an unknown origin, and the Reverb WebSocket upgrade. Authenticated journeys follow the verification matrix in `production-checklist.md`.

## 5. Production configuration rules

```text
APP_ENV=production
APP_DEBUG=false
```

- `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN=.marcaturshub.com.ng`
- Explicit `CORS_ALLOWED_ORIGINS`, `SANCTUM_STATEFUL_DOMAINS`, `REVERB_ALLOWED_ORIGINS` (never `*`)
- `SECURITY_HSTS_ENABLED=true`
- `SENSITIVE_DISK_DRIVER=s3`, `CAMPAIGN_MEDIA_DISK_DRIVER=s3` (private buckets)
- `MAIL_MAILER=resend`
- Paystack test keys until the go-live gate; live keys only after Product approval

Full list: [production-environment.md](production-environment.md).
