# Deployment documentation index (ENG-040 / MH-OPS-001, aligned in MH-OPS-006)

Approved pilot: `marcaturshub.com.ng` + Cloudflare (DNS, Pages) + AWS Lightsail (Nginx, PHP-FPM 8.4, Redis, queue, scheduler, Reverb) + Lightsail Managed MySQL + private S3 + Resend + Paystack, deployed from `main` with GitHub Actions approval gates.

| Document | Purpose |
| -------- | ------- |
| [architecture.md](architecture.md) | Approved pilot topology; ECS path kept as deferred future scale |
| [production.md](production.md) | Lightsail host baseline, process model, release pipeline, migration gate, smoke |
| [production-environment.md](production-environment.md) | Definitive production environment variables (backend, SPAs, CI) |
| [production-checklist.md](production-checklist.md) | Human provisioning checklist, DNS plan, security, verification gate, monitoring, backups, cost |
| [frontend.md](frontend.md) | Participant + Admin SPA builds on Cloudflare Pages |
| [rollback.md](rollback.md) | Release, database, Pages and host rollback |
| [staging.md](staging.md) | **Future** staging (`develop`) — not built; historical ECS bootstrap notes |
| [aws-staging-checklist.md](aws-staging-checklist.md) | **Deferred** ECS/ALB/RDS/ElastiCache resource list (future scale) |

Reference configuration: `infra/lightsail/` (Nginx, Supervisor, PHP, Redis, `deploy.sh`). Smoke: `scripts/smoke.sh`.

## Current readiness

| Layer | Status | Reason |
| ----- | ------ | ------ |
| Local application | GREEN | MH-PRE-004 regression (participant, Admin, backend suites) |
| Repository deployment configuration | GREEN | Pilot env contract, Lightsail configs, approval-gated workflows, Resend SDK installed (MH-OPS-006) |
| Production infrastructure | **NOT PROVISIONED** | Awaiting the human prerequisites in `production-checklist.md` §1 |
| Staging | **NOT PLANNED FOR PILOT** | Future `develop` environment |
