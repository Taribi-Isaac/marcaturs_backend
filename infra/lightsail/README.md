# Lightsail pilot reference configuration (MH-OPS-006)

Templates for the single production host. Nothing here is applied automatically except `deploy.sh`, which the production workflow installs and runs. Install steps and paths: `docs/deployment/production.md`.

| Path | Purpose |
| --- | --- |
| `nginx/api.marcaturshub.com.ng.conf` | TLS, PHP-FPM, upload limit, forwarded-header hardening, default-server rejection |
| `nginx/ws.marcaturshub.com.ng.conf` | TLS + WebSocket proxy to Reverb on `127.0.0.1:8080` |
| `supervisor/marcaturshub.conf` | One queue worker, one scheduler, one Reverb |
| `php/99-marcaturshub.ini` | Upload limits, OPcache, `expose_php=Off` |
| `redis/marcaturshub.conf` | Loopback bind, auth, memory cap |
| `deploy.sh` | Release (with optional migration gate), rollback and status; recovery model in `docs/deployment/rollback.md` |
| `deploy-ci.sh` | Workflow-side rollback decision and job summary |
| `tests/deploy-test.sh` | Stubbed harness for both scripts (temp directory only); run by `tests/Feature/Deployment/DeployScriptTest.php` |

These files have not been exercised against a live host yet; validate them during provisioning (`nginx -t`, `supervisorctl status`, `scripts/smoke.sh`).
