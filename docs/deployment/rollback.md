# Rollback (ENG-040, aligned in MH-OPS-006)

## API on Lightsail (pilot)

Code rollback is a symlink switch to the previous release (five are kept):

```bash
/var/www/marcaturshub/bin/deploy.sh rollback
```

This reloads PHP-FPM and restarts the queue worker, scheduler and Reverb. The production workflow runs it automatically when post-deploy smoke fails. Then re-run `scripts/smoke.sh`.

Rollback does **not** reverse database migrations.

## Database

- Prefer **forward-compatible** (expand-then-contract) migrations so the previous release keeps working after a migration.
- Do **not** assume automatic down-migrations in production.
- If a migration damaged data: put the API in maintenance mode (`php artisan down`; health and the Paystack webhook stay reachable), restore the Lightsail database to the pre-release snapshot or a point in time into a **new** database, switch `DB_HOST` in `shared/.env`, `php artisan config:cache`, reload PHP-FPM, restart `marcaturshub:*`, `php artisan up`. Writes after the restore point are lost — record the window.
- Never `migrate:fresh` / `db:wipe` on production.

## Frontends (Cloudflare Pages)

Cloudflare Pages keeps every deployment. Roll back from the project's **Deployments** list ("Rollback to this deployment"), or re-run the production workflow on an earlier `main` commit.

## Secrets / config

Restore the previous `shared/.env` (keep a copy in the owner's password manager), run `php artisan config:cache` in `current/`, reload PHP-FPM and restart `marcaturshub:*`.

## Host loss

Create a new instance from the latest Lightsail snapshot (or rebuild per `production.md` §1), attach the static IP, confirm certificates, then deploy the current `main` release.

## Future: ECS (deferred)

1. Identify the previous healthy ECR image digest / task definition revision.
2. `UpdateService` to the previous task definition (api, worker, scheduler, reverb as needed).
3. Wait for steady state; re-run `scripts/smoke.sh`.
