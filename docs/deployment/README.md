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
| Repository deployment configuration | GREEN (source, MH-OPS-006) | Pilot env contract, Lightsail configs, workflows that set GitHub Environment `production`, Resend SDK installed. That environment gate is defined in the workflow files. Current GitHub reviewer permissions were not re-read in MH-OPS-103 |
| Production infrastructure | **HISTORICALLY DEPLOYED — ACTIVE RELEASE UNKNOWN** | A Backend production deployment was recorded: release `20261017113503-fe295bc5bf44`, commit `fe295bc5bf4430f905d0ad6f4e1de69404b49807`, GitHub Deployment `7138938636`, Actions run `39029158956`. That record is not a fresh check of the host, services, or the release now serving. MH-OPS-102: Product authorized a read-only `deploy.sh status`; the authorized key-holder has not returned the output. The historical release id is not the current release identity |
| Staging | **NOT PLANNED FOR PILOT** | Future `develop` environment |
