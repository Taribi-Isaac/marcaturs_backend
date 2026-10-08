# Production environment contract (MH-OPS-006)

Definitive list of production configuration. Templates: `backend/.env.production.example`, `frontend/.env.production.example`, `adminControl/.env.production.example`. No real values belong in Git.

Legend — **Req**: production breaks or is insecure without it. **Opt**: safe default / feature degrades safely. **Secret**: never in Git, chat, logs or `VITE_*`. **Provisioned**: value only exists after a service is created.

## Backend (`/var/www/marcaturshub/shared/.env`)

### Application

| Variable | Req | Secret | Production value / format | Purpose |
| --- | --- | --- | --- | --- |
| `APP_NAME` | Req | no | `MarcatursHub` | Mail sender name, cookie/cache prefixes |
| `APP_ENV` | Req | no | `production` | Enables fail-closed Reverb origins, blocks demo seed |
| `APP_KEY` | Req | **yes** | `base64:…` (32 bytes) | Encryption (sessions are encrypted). Losing it logs everyone out and breaks encrypted data |
| `APP_DEBUG` | Req | no | `false` | `deploy.sh` refuses to release otherwise |
| `APP_URL` | Req | no | `https://api.marcaturshub.com.ng` | Signed email-verification links, console URLs |
| `FRONTEND_URL` | Req | no | `https://app.marcaturshub.com.ng` | Password reset, verify redirect, commission and `/pay/` share links, Paystack return URLs |
| `ADMIN_FRONTEND_URL` | Req | no | `https://admin.marcaturshub.com.ng` | Staff invitation links (otherwise falls back to `FRONTEND_URL`) |
| `ADMIN_URL` | Opt | no | `https://admin.marcaturshub.com.ng` | Local-only Reverb fallback; harmless in production |
| `LOG_CHANNEL` / `LOG_STACK` | Req | no | `stack` / `daily` | Files in `shared/storage/logs`, redaction processor attached |
| `LOG_LEVEL` | Opt | no | `info` | |
| `LOG_DAILY_DAYS` | Opt | no | `14` | Log retention on disk |

### Database (Lightsail Managed MySQL 8)

| Variable | Req | Secret | Format | Purpose |
| --- | --- | --- | --- | --- |
| `DB_CONNECTION` | Req | no | `mysql` | |
| `DB_HOST` | Req | no | Provisioned private endpoint (`ls-….eu-north-1.rds.amazonaws.com`) | |
| `DB_PORT` | Req | no | `3306` | |
| `DB_DATABASE` | Req | no | `marcaturshub` | |
| `DB_USERNAME` | Req | no | Provisioned app user (not the master user) | |
| `DB_PASSWORD` | Req | **yes** | Provisioned | |
| `MYSQL_ATTR_SSL_CA` | Req | no | `/etc/ssl/certs/aws-rds-global-bundle.pem` | TLS to the managed database (download the AWS RDS global bundle to this path) |

### Sessions, Sanctum, CORS

| Variable | Req | Secret | Production value | Purpose |
| --- | --- | --- | --- | --- |
| `SESSION_DRIVER` | Req | no | `redis` | |
| `SESSION_LIFETIME` | Opt | no | `120` | Idle minutes |
| `SESSION_ENCRYPT` | Req | no | `true` | |
| `SESSION_DOMAIN` | Req | no | `.marcaturshub.com.ng` | Lets `app.*`/`admin.*` read `XSRF-TOKEN` set by `api.*` |
| `SESSION_SECURE_COOKIE` | Req | no | `true` | HTTPS-only cookies |
| `SESSION_SAME_SITE` | Req | no | `lax` | Subdomains of one registrable domain are same-site |
| `SANCTUM_STATEFUL_DOMAINS` | Req | no | `app.marcaturshub.com.ng,admin.marcaturshub.com.ng` | Hosts that get cookie (SPA) auth |
| `SANCTUM_TOKEN_EXPIRATION_MINUTES` | Opt | no | `10080` | 7-day PAT lifetime (MH-BE-047) |
| `CORS_ALLOWED_ORIGINS` | Req | no | `https://app.marcaturshub.com.ng,https://admin.marcaturshub.com.ng` | Exact origins; never `*` (credentials are enabled) |

### Redis, queue, cache, broadcasting

| Variable | Req | Secret | Production value | Purpose |
| --- | --- | --- | --- | --- |
| `REDIS_CLIENT` | Req | no | `phpredis` | |
| `REDIS_HOST` | Req | no | `127.0.0.1` | Redis on the app host |
| `REDIS_PORT` | Req | no | `6379` | |
| `REDIS_PASSWORD` | Req | **yes** | Matches Redis `requirepass` | |
| `QUEUE_CONNECTION` | Req | no | `redis` | |
| `CACHE_STORE` | Req | no | `redis` | |
| `BROADCAST_CONNECTION` | Req | no | `reverb` | |

### Reverb

| Variable | Req | Secret | Production value | Purpose |
| --- | --- | --- | --- | --- |
| `REVERB_APP_ID` | Req | no | Random id generated at provisioning | |
| `REVERB_APP_KEY` | Req | no (public) | Random key; **same value** as frontend `VITE_REVERB_APP_KEY` | Client identification |
| `REVERB_APP_SECRET` | Req | **yes** | Random secret | Signs server→Reverb publishes and channel auth |
| `REVERB_HOST` | Req | no | `ws.marcaturshub.com.ng` | Where Laravel publishes events |
| `REVERB_PORT` | Req | no | `443` | |
| `REVERB_SCHEME` | Req | no | `https` | |
| `REVERB_SERVER_HOST` | Req | no | `127.0.0.1` | Loopback bind; 8080 never public |
| `REVERB_SERVER_PORT` | Req | no | `8080` | |
| `REVERB_ALLOWED_ORIGINS` | Req | no | `app.marcaturshub.com.ng` | Explicit; empty = fail-closed; `*` stripped (MH-BE-050). Admin has no realtime client |
| `REVERB_SCALING_ENABLED` | Opt | no | `false` | Single node |

### Security headers

| Variable | Req | Secret | Production value |
| --- | --- | --- | --- |
| `SECURITY_HSTS_ENABLED` | Req | no | `true` |
| `SECURITY_HSTS_MAX_AGE` | Opt | no | `31536000` |

### Private S3

| Variable | Req | Secret | Format | Purpose |
| --- | --- | --- | --- | --- |
| `SENSITIVE_DISK_DRIVER` | Req | no | `s3` | Verification, payment evidence, disputes, certification resources/certificates |
| `CAMPAIGN_MEDIA_DISK_DRIVER` | Req | no | `s3` | Campaign covers + marketing resources |
| `SENSITIVE_AWS_BUCKET` | Req | no | Provisioned, e.g. `marcaturshub-prod-sensitive-<suffix>` | |
| `CAMPAIGN_MEDIA_AWS_BUCKET` | Req | no | Provisioned, e.g. `marcaturshub-prod-campaign-media-<suffix>` | |
| `AWS_ACCESS_KEY_ID` | Req | **yes** | IAM user limited to the two buckets | Lightsail cannot assume IAM roles |
| `AWS_SECRET_ACCESS_KEY` | Req | **yes** | | |
| `AWS_DEFAULT_REGION` | Req | no | `eu-north-1` | Same region as the host |
| `SENSITIVE_S3_ROOT` / `CAMPAIGN_MEDIA_S3_ROOT` | Opt | no | `sensitive` / `campaign-media` | Key prefixes |
| `FILESYSTEM_DISK` | Opt | no | `local` | No feature writes to the default disk |
| `CAMPAIGN_MEDIA_DISK`, `VERIFICATION_EVIDENCE_DISK`, `DEAL_PAYMENT_EVIDENCE_DISK` | Opt | no | `campaign_media`, `sensitive`, `sensitive` | Defaults already correct |

### Mail (Resend)

| Variable | Req | Secret | Production value | Purpose |
| --- | --- | --- | --- | --- |
| `MAIL_MAILER` | Req | no | `resend` | Laravel Resend transport (`resend/resend-php`) |
| `RESEND_API_KEY` | Req | **yes** | `re_…` (sending-only key) | |
| `MAIL_FROM_ADDRESS` | Req | no | `no-reply@marcaturshub.com.ng` (Product to confirm) | Must be on the Resend-verified domain |
| `MAIL_FROM_NAME` | Opt | no | `MarcatursHub` | |
| `MAIL_REPLY_TO_ADDRESS` / `MAIL_REPLY_TO_NAME` | Opt | no | e.g. `support@marcaturshub.com.ng` | No Reply-To header when unset |

### Paystack (platform purchases only)

| Variable | Req | Secret | Production value | Purpose |
| --- | --- | --- | --- | --- |
| `PAYSTACK_SECRET_KEY` | Req | **yes** | `sk_test_…` until go-live, then `sk_live_…` | Initialize/verify; also verifies webhook signatures (HMAC-SHA512) |
| `PAYSTACK_PUBLIC_KEY` | Opt | no | `pk_…` | Not used by current code (hosted checkout) |
| `PAYSTACK_BASE_URL` | Opt | no | `https://api.paystack.co` | |
| `PAYSTACK_CALLBACK_URL` | Opt | no | empty | Return URLs come from `FRONTEND_URL`; never set to the webhook URL |

Webhook URL to register in the Paystack dashboard: `https://api.marcaturshub.com.ng/api/v1/webhooks/paystack` (excluded from maintenance mode).

### Rate limits and product tunables (optional, defaults shown)

`API_RATE_LIMIT_PER_MINUTE=60`, `LOGIN_RATE_LIMIT_PER_MINUTE=5`, `REGISTRATION_RATE_LIMIT_PER_MINUTE=5`, `PASSWORD_RESET_RATE_LIMIT_PER_MINUTE=5`, `EMAIL_VERIFICATION_RATE_LIMIT_PER_MINUTE=5`, `UPLOAD_RATE_LIMIT_PER_MINUTE=10`, `OFFICIAL_PAYMENT_RATE_LIMIT_PER_MINUTE=30`, `DEAL_COMMISSION_PAYMENT_CEILING_DAYS=7` (must stay in step with the frontend constant), upload size limits (`*_MAX_KB`).

### Not used in production

`RUN_MIGRATIONS` (container entrypoint only), `MAIL_HOST`/`MAIL_PORT`/`MAIL_USERNAME`/`MAIL_PASSWORD` (SMTP), `MAIL_LOG_CHANNEL`, `AWS_BUCKET`, `ALLOW_DEMO_SEED`, any SES variables.

## Frontend build variables (public)

Set as **variables** (not secrets) on GitHub Environment `production`. Everything here is visible in the browser bundle.

| Variable | App | Req | Production value |
| --- | --- | --- | --- |
| `VITE_API_BASE_URL` | Participant, Admin | Req | `https://api.marcaturshub.com.ng/api/v1` |
| `VITE_BACKEND_ORIGIN` | Participant, Admin | Req | `https://api.marcaturshub.com.ng` (dev proxy only; kept for parity) |
| `VITE_PUBLIC_ORIGIN` | Participant | Req | `https://app.marcaturshub.com.ng` (canonical / Open Graph) |
| `VITE_REVERB_APP_KEY` | Participant | Req | Same as backend `REVERB_APP_KEY` |
| `VITE_REVERB_HOST` | Participant | Req | `ws.marcaturshub.com.ng` |
| `VITE_REVERB_PORT` | Participant | Req | `443` |
| `VITE_REVERB_SCHEME` | Participant | Req | `https` |

The deploy workflows fail if any of these is missing, uses `http://`, or points at `localhost`, `127.0.0.1` or `example.com`.

## CI secrets and variables (GitHub Environment `production`)

| Repo | Secrets | Variables |
| --- | --- | --- |
| backend | `LIGHTSAIL_SSH_KEY` (deploy user private key), `LIGHTSAIL_SSH_KNOWN_HOSTS` | `LIGHTSAIL_HOST`, `LIGHTSAIL_USER`, `PRODUCTION_API_URL`, `PRODUCTION_APP_URL`, `PRODUCTION_ADMIN_URL`, `PRODUCTION_WS_URL`, `REVERB_APP_KEY` |
| frontend | `CLOUDFLARE_API_TOKEN` (Pages:Edit), `CLOUDFLARE_ACCOUNT_ID` | `CF_PAGES_PROJECT` + the `VITE_*` above |
| adminControl | `CLOUDFLARE_API_TOKEN`, `CLOUDFLARE_ACCOUNT_ID` | `CF_PAGES_PROJECT`, `VITE_API_BASE_URL`, `VITE_BACKEND_ORIGIN` |

Configure the environment with **required reviewers** and restrict deployments to the `main` branch.
