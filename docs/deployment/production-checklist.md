# Production provisioning & go-live checklist (MH-OPS-006)

Human-owned checklist for the Lightsail pilot. This is the original MH-OPS-006 provisioning list. Later records include a Backend production deployment and two Cloudflare Pages projects (MH-OPS-104). A box below is complete only when a person has confirmed that item. This revision does not check the boxes and does not re-verify the Lightsail host. The 2026-10-20 `deploy`-account status in [rollback.md](rollback.md) is historical evidence, not a standing host identity. Work top to bottom; do not skip the verification gate.

## 1. Human prerequisites (owner provides)

- [ ] AWS account with a named billing owner, MFA on root, budget alert (e.g. $60/month)
- [ ] IAM admin user for the operator (no root keys); Lightsail + S3 + IAM access in `eu-north-1`
- [ ] `marcaturshub.com.ng` registered (NiRA-accredited registrar) and nameservers delegated to Cloudflare
- [ ] Cloudflare account (free plan) with the zone active; API token for Pages deploys (Pages:Edit only)
- [ ] GitHub admin access to the three repositories; Environment `production` with required reviewers
- [ ] Resend account; sending domain added; plan chosen (see cost — free tier caps 100 emails/day)
- [ ] Paystack business account; **test** keys for first deployment; live keys only at the go-live gate
- [ ] Product decisions: apex/`www` behaviour, From/Reply-To addresses, go-live date, on-call contact

## 2. Domain / DNS plan (apply in Cloudflare — values come from each service)

| Host | Type | Target | Proxy | Purpose |
| --- | --- | --- | --- | --- |
| `app` | CNAME | `marcaturshub-app.pages.dev` (MH-OPS-104 observation; custom domain `app.marcaturshub.com.ng`) | Proxied | Participant SPA |
| `admin` | CNAME | `marcaturshub-admin.pages.dev` (MH-OPS-104 observation; custom domain `admin.marcaturshub.com.ng`) | Proxied | Admin SPA |
| `api` | A | Lightsail static IP | **DNS only** | Laravel API (Nginx TLS) |
| `ws` | A | Lightsail static IP | **DNS only** | Reverb WebSockets |
| `@` (apex) | A/CNAME + Redirect Rule | Cloudflare redirect to `https://app.marcaturshub.com.ng` | Proxied | **Product to confirm** (alternative: serve the SPA on apex — then add the apex origin to CORS, Sanctum, Reverb and `VITE_PUBLIC_ORIGIN`) |
| `www` | CNAME + Redirect Rule | → `https://app.marcaturshub.com.ng` (or apex) | Proxied | Redirect |
| Resend DKIM | TXT/CNAME | Exact records shown by Resend | DNS only | Mail signing |
| Resend return path | MX + TXT (SPF) on the Resend subdomain (e.g. `send`) | Exact records shown by Resend | DNS only | Bounce handling / SPF alignment |
| `_dmarc` | TXT | `v=DMARC1; p=none; rua=mailto:<monitored address>` → tighten to `quarantine` after 2–4 clean weeks | DNS only | DMARC |
| Apex SPF | TXT | Only if the apex itself sends mail; otherwise `v=spf1 -all` | DNS only | Prevent spoofing |
| CAA | CAA | `letsencrypt.org` + Cloudflare's CAs | — | Restrict certificate issuers |

Do not invent record values; copy them from Cloudflare Pages, Lightsail and Resend when those exist. No MX for receiving mail unless Product sets up a mailbox.

## 3. Infrastructure

### Lightsail instance
- [ ] Ubuntu 24.04, 2 GB plan, `eu-north-1`; static IP attached
- [ ] Firewall: 80, 443, and 22 key-only (GitHub-hosted deploy runners need it; fail2ban + `AllowUsers ubuntu deploy`)
- [ ] Automatic daily instance snapshots enabled
- [ ] Host baseline per `production.md` §1 (PHP 8.4, Nginx, Redis, Supervisor, certbot), `deploy` user, sudoers entry, directory layout, swap
- [ ] Unattended security upgrades enabled

### Managed MySQL
- [ ] Lightsail Managed Database, MySQL 8, standard plan, same region, **public mode off**
- [ ] Database `marcaturshub` (utf8mb4) and an application user with privileges on that schema only
- [ ] Automated backups on (7-day point-in-time restore)
- [ ] AWS RDS CA bundle at `/etc/ssl/certs/aws-rds-global-bundle.pem`; connection tested from the instance with TLS

### Redis
- [ ] Bound to loopback, `requirepass` set, `maxmemory 256mb`, `volatile-lru` (`infra/lightsail/redis/marcaturshub.conf`)

### S3
- [ ] Two buckets in `eu-north-1`: sensitive, campaign-media (globally unique names)
- [ ] Block Public Access ON (all four settings); Object Ownership "Bucket owner enforced" (ACLs disabled)
- [ ] Default encryption SSE-S3 (on by default); versioning ON; lifecycle: expire noncurrent versions after 30 days (adjust to Product retention policy)
- [ ] No bucket policy granting public or cross-account access
- [ ] IAM user `marcaturshub-prod-app` with only `s3:GetObject`, `s3:PutObject`, `s3:DeleteObject` on `arn:aws:s3:::<bucket>/*` and `s3:ListBucket` on both bucket ARNs; access key stored only in `shared/.env`

### TLS / edge
- [ ] `certbot certonly --webroot -w /var/www/letsencrypt -d api.marcaturshub.com.ng -d ws.marcaturshub.com.ng`; renewal dry-run passes
- [ ] Cloudflare SSL mode "Full (strict)" for proxied hosts; "Always Use HTTPS" on; HSTS at the edge for `app`/`admin` once stable

### Cloudflare Pages

MH-OPS-104 observed project `marcaturshub-app` (production alias `6dcae7ca.marcaturshub-app.pages.dev`) and project `marcaturshub-admin` (production alias `0cfca432.marcaturshub-admin.pages.dev`). Both showed “No Git connection.” Those aliases are inspection records, not Git commit SHAs, and they are not a guarantee of the deployment serving later.

- [ ] Two **Direct Upload** projects (no Git integration): participant and admin
- [ ] Custom domains `app.` and `admin.` attached
- [ ] No `404.html` in the build so Pages serves `index.html` for SPA routes (current builds comply)

## 4. Application

- [ ] `shared/.env` populated from `.env.production.example`; `APP_KEY` and Reverb id/key/secret generated; file mode 0640
- [ ] GitHub Environment `production` secrets/variables set (see `production-environment.md`)
- [ ] First release with `migrate = true`; `php artisan migrate:status` shows no pending migrations
- [ ] First admin created with `php artisan marcaturs:create-admin` (no demo seed)
- [ ] Supervisor shows `marcaturshub-queue`, `marcaturshub-scheduler`, `marcaturshub-reverb` RUNNING; no cron `schedule:run`
- [ ] Storage: upload + authorized download works for a campaign cover and a payment evidence file; objects are not publicly readable by URL
- [ ] Mail: Resend domain verified; verification and password-reset emails received; links point to `app.marcaturshub.com.ng`
- [ ] Paystack: test keys; webhook URL registered; test purchase of extension / Featured / certification completes and the webhook is accepted

## 5. Security

- [ ] `APP_ENV=production`, `APP_DEBUG=false` (an error page shows no stack trace)
- [ ] HTTPS everywhere; HTTP redirects to HTTPS; HSTS header on the API
- [ ] Security headers present; no `X-Powered-By` (`scripts/smoke.sh`)
- [ ] CORS, Sanctum and Reverb origins are explicit lists; unknown origin rejected (smoke)
- [ ] Cookies: `Secure`, `HttpOnly` (session), `SameSite=Lax`, domain `.marcaturshub.com.ng`
- [ ] Ports 3306/6379/8080 unreachable from the internet (`nc -vz <ip> 6379` from outside fails)
- [ ] Secrets only in `shared/.env` and GitHub Environment secrets; none in Git history, `VITE_*`, or logs
- [ ] Log redaction active (no Paystack signatures, tokens or account numbers in `laravel.log`)
- [ ] SSH key-only login; password auth disabled

## 6. Verification gate (before announcing the pilot)

Run `scripts/smoke.sh`, then these journeys against production with test data and **Paystack test keys**:

| Area | Check |
| --- | --- |
| Health | `/api/v1/health` 200 with database `ok`; `/up` 200 |
| Authentication | Register Business + Ambassador, verify email, login/logout on `app.`, `/me`, password reset; admin login on `admin.` |
| Business | Profile, verification submission (sensitive upload) |
| Campaign | Create campaign, cover upload, marketing resource upload/download |
| Campaign Version | Draft → publish with commission deadline 1–7 days |
| Marketplace | Campaign visible on `/discover`; `/pay/<token>` shows direct-payment wording |
| Deals | Ambassador creates deal; Business sees it |
| Payment evidence | Upload + Business confirm; files private |
| Commission | Commission created with due date ≤7 days; reminders appear after a scheduler cycle (≤15 min) |
| Certification | Test-mode purchase → enrollment email → assessment → certificate PDF (queue) |
| Notifications | In-app + email delivered (queue worker) |
| Chat / Reverb | Two browsers in a conversation receive `message.created` live over `wss://ws.` |
| Email | Resend dashboard shows delivered; SPF/DKIM/DMARC pass in message headers |
| Paystack boundary | Only platform purchases use Paystack; customer → Business payment is direct (no Paystack) |
| Admin | Campaign/deal/dispute views load; staff invite link points to `admin.` |

**Go-live gate (separate approval):** switch to Paystack live keys, run one low-value live platform purchase, confirm the webhook, then announce.

## 7. Monitoring (minimum for the pilot)

| Signal | How |
| --- | --- |
| API health | External uptime monitor (free tier) on `https://api.marcaturshub.com.ng/api/v1/health` every 1–5 min (503 = database down) |
| SPAs | Uptime checks on `app.` and `admin.` |
| TLS expiry | Uptime monitor certificate alerts + certbot timer (`systemctl list-timers`) |
| Reverb | `scripts/smoke.sh` WebSocket check after deploys; Supervisor autorestart; `reverb.log` |
| HTTP 5xx | `nginx/api.access.log` + `storage/logs/laravel-*.log` reviewed daily during the pilot |
| Queue failures | `php artisan queue:failed` empty; `queue.log` |
| Scheduler | `scheduler.log` shows runs every 15 minutes |
| Database | Lightsail database metric alarms: CPU, free storage, connections |
| Redis | `redis-cli -a … ping`; included in `/api/v1/health` cache status |
| Host CPU / status | Lightsail instance metric alarms (CPU, status check failed) with email notification |
| Memory / disk | `free -m`, `df -h` weekly; alert threshold 80% (Lightsail has no native memory metric) |
| Email delivery / bounces | Resend dashboard + its email alerts for bounces/complaints |
| Paystack webhooks | Paystack dashboard webhook delivery log; failed webhooks are retried by Paystack |

A heavier stack (CloudWatch agent, APM) is deferred until the pilot shows a need.

## 8. Backup and recovery

| Asset | Mechanism | Retention | RPO | RTO |
| --- | --- | --- | --- | --- |
| MySQL | Lightsail automated daily backups + point-in-time restore | 7 days (fixed by Lightsail) | ~5 minutes | ~30–60 min (restore to new DB, update `DB_HOST`, reload) |
| MySQL (pre-migration) | Manual snapshot before every migration release | Until next successful release + 30 days | Snapshot time | As above |
| S3 objects | Versioning (accidental delete/overwrite) | Noncurrent versions 30 days | ~0 | Minutes per object |
| App host | Lightsail automatic daily snapshots | 7 days | 24 h (host holds no unique data besides `shared/.env` and logs) | ~30–60 min from snapshot, or 1–2 h rebuild from this runbook |
| Secrets | `shared/.env` copy in the owner's password manager | Current | — | Minutes |

Meets TAD §70 (RPO 15–60 min, RTO 1–4 h).

**Restore test (monthly during the pilot):** restore the database to a new temporary Lightsail database at a recent point in time, connect from the instance, check row counts for `users`, `campaigns`, `deals`, `commissions` and the latest migration, then delete the temporary database. **Quarterly:** create an instance from the latest snapshot with no DNS pointing to it, confirm Nginx/Supervisor start, then delete it.

## 9. Cost (pilot estimate — verify prices at purchase)

| Item | Estimate / month |
| --- | --- |
| Lightsail instance 2 GB | ≈ $12 (approved compute target) |
| Lightsail Managed MySQL, smallest standard plan | ≈ $15 |
| Lightsail instance snapshots (~60 GB × 7) | a few dollars |
| S3 (low GB, private) | < $1–2 |
| Resend | $0 (free: 3,000/month, **100/day**) or ≈ $20 (Pro) |
| Cloudflare DNS + Pages | $0 |
| GitHub Actions | $0 within free minutes |
| Paystack | Per-transaction fees only |
| Domain `.com.ng` | Annual registrar fee |

Expected total ≈ **$30–55/month**. The $12 target covers compute only; the database and Resend are separately approved supporting costs. Material exceedances to flag: the 4 GB instance (+$12) if memory is tight, a larger database plan, Resend Pro if daily volume passes 100, and any HA/multi-AZ option (not approved).
