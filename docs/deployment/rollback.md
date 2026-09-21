# Rollback (ENG-040)

## Application (ECS)

1. Identify previous healthy ECR image digest / task definition revision.
2. `UpdateService` to previous task definition (api, worker, scheduler, reverb as needed).
3. Wait for steady state; re-run `scripts/staging-smoke.sh` (or production equivalent).

## Frontends (S3 + CloudFront)

1. Redeploy prior `dist/` prefix or restore versioned objects.
2. Create CloudFront invalidation for `/*` (or known asset paths).

## Database

- Prefer **forward-compatible** migrations.
- Do **not** assume automatic down-migrations in production.
- Risky schema changes require an explicit reverse migration tested on staging first.
- Never `migrate:fresh` / `db:wipe` on staging persistent data or production.

## Secrets / config

Roll back task definition env/secrets to the prior Secrets Manager version if a bad secret was injected.
