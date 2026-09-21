# MH-OPS-001 / ENG-040 — Deployment documentation index

| Document | Purpose |
| -------- | ------- |
| [architecture.md](architecture.md) | TAD-aligned staging/production topology |
| [staging.md](staging.md) | Staging bootstrap runbook, env, smoke, blockers |
| [aws-staging-checklist.md](aws-staging-checklist.md) | AWS resource checklist (apply only with account access) |
| [production.md](production.md) | Production promotion gate — **do not deploy until staging GREEN** |
| [frontend.md](frontend.md) | Participant + Admin SPA build/hosting |
| [rollback.md](rollback.md) | Image / frontend / migration rollback |

## Current readiness (this workspace)

| Layer | Status | Reason |
| ----- | ------ | ------ |
| Local application | GREEN | Prior MH-GATE-009/010 + local suites |
| In-repo deploy foundation | GREEN | Docker multi-target, Compose parity, CI gate, env templates, docs, smoke script, IaC scaffold |
| Live AWS staging | **RED** | No AWS CLI/credentials, no Docker on this agent host, no domain/DNS ownership, GitHub not authenticated |
| Production | **RED** | Staging not verified; domains/secrets not supplied |

**ENG-040 Definition of Done** for *live* staging cannot be claimed until External Access prerequisites in `staging.md` are met and smoke verification passes against a real staging URL.
