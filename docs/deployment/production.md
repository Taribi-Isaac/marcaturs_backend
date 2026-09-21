# Production promotion (ENG-040) — gate only

**Do not deploy production from this document until staging is GREEN.**

This file defines the promotion procedure required by TAD §67–68 and ENG-040. It is intentionally **not** an authorization to cut over DNS or live Paystack keys.

## Hard prerequisites

All must be true:

1. Live AWS **staging** exists and is reachable over HTTPS.
2. `scripts/staging-smoke.sh` passes against staging API.
3. Staging verification matrix in `staging.md` completed (auth, commerce sample, queue, scheduler, Reverb, storage, mail controlled, Paystack **test**).
4. Production domain names supplied by Product/Ops (do not invent).
5. Production AWS account (or isolated production resources) provisioned — separate from staging DB/Redis/S3/secrets.
6. Production Secrets Manager populated (no Git).
7. ACM certificates issued for production hosts.
8. Controlled approval recorded (PR + environment protection on GitHub `production`).

If any item fails: **STOP**. Staging remains the active deploy target.

## Production configuration rules

```text
APP_ENV=production
APP_DEBUG=false
```

- `SESSION_SECURE_COOKIE=true`
- Explicit `CORS_ALLOWED_ORIGINS` and `REVERB_ALLOWED_ORIGINS` (never `*`)
- `SECURITY_HSTS_ENABLED=true` (edge HSTS preferred in addition)
- `FILESYSTEM` / sensitive disks → private S3
- Paystack **live** keys only after staging payment UAT
- Separate RDS, Redis, S3, Secrets, Paystack, mail, Reverb credentials from staging

Template: `backend/.env.production.example` (placeholders only).

## Promotion sequence (when authorized)

1. Tag release from main after CI green.
2. Build/push ECR images (`api`, `worker`, `scheduler`, `reverb`).
3. Run migrations once via controlled task (`RUN_MIGRATIONS=true` on a single release job) — never `migrate:fresh`.
4. Rolling ECS update: worker/scheduler/reverb then api (or blue/green if adopted).
5. Deploy SPA builds to production S3 + CloudFront invalidation.
6. Smoke: health, auth `/me`, security headers, representative Admin + participant flows.
7. Monitor CloudWatch for 15–60 minutes.

## Explicit non-goals for ENG-040 in this workspace

- No production DNS cutover performed.
- No production AWS resources provisioned (blocked — no account access).
- No live Paystack keys configured.
