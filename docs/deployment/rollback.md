# Rollback (ENG-040, aligned in MH-OPS-006)

## API on Lightsail (pilot)

Recovery model (MH-OPS-007D-R3). All commands run as the `deploy` user (`sudo -u deploy …` from an operator shell), never as root, so file ownership stays correct.

### Release states

A release is **activated** only after `current` points at it, PHP-FPM reloaded, the `marcaturshub:*` Supervisor group restarted and the health check passed (`php artisan --version`, plus `HEALTH_URL` → HTTP 200 with `"success":true` when the workflow supplies it). Then:

- `releases/<id>/.deploy-activated` is written;
- `state/active` records `active=<id>` and `previous=<last activated release or none>` (written atomically);
- `state/history.log` gets an `activated` line.

Rollback targets come only from `state/active`. A directory in `releases/` that never completed activation is never a target, whatever its name.

Read production status as the `deploy` account. `state/` is not readable by the `ubuntu` login.

```bash
sudo -n -u deploy /var/www/marcaturshub/bin/deploy.sh status
```

The command is read-only. It prints `current`, `current_activated`, `recorded_active`, `recorded_previous`, `rollback_target`, `state` and `lock`.

`none` from an account that cannot read `state/` is not evidence that the deployment records are missing. On 2026-10-20, the same read-only command run as `ubuntu` printed `recorded_active=none`, `recorded_previous=none`, and `rollback_target=none` with `state=ok`. Re-run as `deploy` before treating the bookkeeping as empty.

The Product owner then ran the `deploy` command above. It exited 0 and reported current release `20261017113503-fe295bc5bf44` with `current_activated=yes`, previous release and rollback target `20261016135712-0f924af61a6b`, `state=ok`, and `lock=free`. That is historical evidence of the host at that inspection. A later release or rollback replaces it. Take a fresh `deploy`-account status before relying on those ids.

### Exit codes

| Code | Meaning | What changed |
| --- | --- | --- |
| 0 | Success | — |
| 2 | Usage error / invalid release id | Nothing |
| 10 | Release failed **before activation** (artifact, `.env` checks, caches, migration preflight or migration, symlink switch) | `current` unchanged; the candidate moved to `failed/` |
| 20 | Release failed **after activation** (PHP-FPM reload/active, Supervisor restart, health) | The new release is `current` but unverified; it is left in place — roll back |
| 30 | Rollback impossible: no previous successful release (first release) | Nothing |
| 31 | Rollback refused: recorded target missing, never activated, missing files, or state unreadable | Nothing |
| 32 | Rollback failed during switch, service restart or health check | `current` may already point at the target; manual recovery |
| 75 | Another release/rollback holds `state/lock` (or a stale lock) | Nothing |

Every release/rollback ends with a `DEPLOY_RESULT action=… rc=… release=… current=…` line.

### Code rollback

```bash
/var/www/marcaturshub/bin/deploy.sh rollback
```

1. Takes `state/lock`; refuses (75) if another run holds it.
2. Picks the target from `state/active`: `previous` if `current` is the recorded active release, otherwise `active` (a release that failed after activation is replaced by the last verified one).
3. Validates the target: valid id, real directory, activation marker, `artisan`, `vendor/autoload.php`, `public/index.php`, `bootstrap/cache/config.php`, `.env`. Any failure → 31, nothing switched.
4. Switches `current` atomically, reloads PHP-FPM, checks it is active, restarts `marcaturshub:*`, runs the health check. Any failure → 32.
5. On success records `active=<target>`, `previous=none`, and moves the rolled-back release to `failed/<id>.<UTC>.rolled-back`.

Then re-run `scripts/smoke.sh` (the workflow does this automatically). Rollback switches code only and does **not** reverse database migrations. Only one level of automatic rollback exists; after a rollback, `previous` is `none`.

**If rollback exits 32:** do not re-run it blindly. Run `deploy.sh status`, read `state/history.log` and `/var/log/marcaturshub/`, check `systemctl status php8.4-fpm` and `supervisorctl status`, fix the service or point `current` at a verified release by hand, then run `scripts/smoke.sh`.

### Failed releases (`failed/`)

`failed/<id>.<UTC>.<reason>/` holds releases that failed before activation (`failed`), leftovers from an interrupted attempt that are moved aside when the same release id is retried (`stale-attempt`), and releases replaced by a rollback (`rolled-back`). They are kept for investigation, are never rollback targets, and are never deleted automatically — remove old entries by hand. The script never moves the current, recorded active or recorded previous release. If a move fails it prints a warning naming the directory. A release that failed after activation stays in `releases/` (it is serving) until a rollback or the next release replaces it.

Re-running a failed workflow with the same release id works: a leftover that never activated is moved aside; an id that has ever been active is refused (start a new workflow run, which builds a new release id).

`deploy.sh` keeps the five newest releases in `releases/` and never prunes `current`, the recorded active or the recorded previous release.

### Stale lock

`state/lock/owner` records `pid`, `action` and `started_at`. If a run was killed (runner cancelled, SSH dropped), the next run exits 75 and names the lock. Confirm no `deploy.sh` process is running (`pgrep -af deploy.sh`), check `deploy.sh status`, then `rm -r /var/www/marcaturshub/state/lock`.

### First release has no rollback target

The first release has nothing to roll back to: `deploy.sh rollback` exits **30** and leaves the release in place, and the workflow reports "FAILED (FIRST RELEASE) … Manual recovery required". Before production has ever served traffic:

1. Read the workflow log and `deploy.sh status`; on the host, `/var/www/marcaturshub/shared/storage/logs/` and `/var/log/marcaturshub/`.
2. If the cause is configuration (`shared/.env`, services), fix it and re-run the workflow (a new run builds a new release id).
3. If the cause is code, fix it on `main` and deploy again.
4. If the database was partially migrated, see **Database** below — a restore needs explicit approval.

Optional (proposed, not implemented): an operator-only `deploy.sh deactivate` that removes `current` and stops `marcaturshub:*`, so a broken first release stops serving and Nginx returns 502/404. It would need its own sudoers entry (`supervisorctl stop marcaturshub:*`) and approval before being added.

### Automatic recovery in the production workflow

| Failure | Workflow behaviour |
| --- | --- |
| Before activation (exit 10, upload failure, lock) | No rollback; the previous release keeps serving |
| After activation (exit 20) or smoke failure | `deploy.sh status` confirms the new release is current → `deploy.sh rollback` → smoke again |
| Rollback exit 30 (first release) | Reported as first-release manual recovery; job fails |
| Rollback exit 31/32 | Reported as rollback failed; job fails; manual recovery |
| Server state unknown (SSH failed) | No rollback; manual check |

The job always fails when the deployment failed, even if the rollback succeeded. The job summary shows the release, smoke, recovery decision, rollback, post-rollback smoke and the active release.

## Database

- Prefer **forward-compatible** (expand-then-contract) migrations so the previous release keeps working after a migration.
- Do **not** assume automatic down-migrations in production. Neither `deploy.sh` nor the workflow ever restores, rolls back or wipes the database.
- A failed `--migrate` release may leave the database partially migrated (MySQL DDL is not transactional). Code stays on the previous release; read the migration error before retrying.
- A database restore is a manual operation that requires explicit Product/Engineering approval.
- If a migration damaged data (after approval): put the API in maintenance mode (`php artisan down`; health and the Paystack webhook stay reachable), restore the Lightsail database to the pre-release snapshot or a point in time into a **new** database, switch `DB_HOST` in `shared/.env`, `php artisan config:cache`, reload PHP-FPM, restart `marcaturshub:*`, `php artisan up`. Writes after the restore point are lost — record the window.
- Never `migrate:fresh` / `db:wipe` on production.

## Frontends (Cloudflare Pages)

The Participant and Admin production workflows cannot publish an arbitrary older commit. In `frontend-dev-main` and `adminControl-dev-main`, `.github/workflows/production.yml` deploys only when the event is `workflow_dispatch`, `deploy` is true, and `github.ref` is `refs/heads/main`. The job checks out that ref. There is no commit input and no rollback input.

MH-OPS-104 (read-only Cloudflare dashboard) observed historical production deployment records on both projects, and a rollback control on each project. The control appeared enabled for the authorized Product operator. It was not used. Confirmation steps, the deployment the control would select, recovery success, and health after recovery were not recorded. A visible control is not evidence that a rollback succeeds.

Before a separately authorized recovery:

1. Open the Cloudflare account that owns the intended project: `marcaturshub-app` (Participant, `app.marcaturshub.com.ng`) or `marcaturshub-admin` (Admin, `admin.marcaturshub.com.ng`).
2. Read the deployment that is marked production. MH-OPS-104 saw `6dcae7ca.marcaturshub-app.pages.dev` and `0cfca432.marcaturshub-admin.pages.dev`. Treat those aliases as that inspection’s record, not as commit SHAs and not as a promise they are still production.
3. Choose an earlier **production** deployment from that project’s history. MH-OPS-104 also listed earlier production aliases `f89a355a`, `7e67995b`, and `716fa81a` on the Participant project, and `4511e6ac.marcaturshub-admin.pages.dev` on the Admin project. The dashboard did not state which of those is the immediate predecessor.
4. Use the rollback control only after a separate Product authorization. Stop if the control is absent or disabled for the operator.

## Secrets / config

Restore the previous `shared/.env` (keep a copy in the owner's password manager), run `php artisan config:cache` in `current/`, reload PHP-FPM and restart `marcaturshub:*`.

## Host loss

Create a new instance from the latest Lightsail snapshot (or rebuild per `production.md` §1), attach the static IP, confirm certificates, then deploy the current `main` release.

## Future: ECS (deferred)

1. Identify the previous healthy ECR image digest / task definition revision.
2. `UpdateService` to the previous task definition (api, worker, scheduler, reverb as needed).
3. Wait for steady state; re-run `scripts/smoke.sh`.
