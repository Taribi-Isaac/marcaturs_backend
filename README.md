# MarcatursHub Backend

API-first Laravel service for MarcatursHub (TARIZEFA LIMITED).

This repository is the backend engineering foundation. Business workflows (registration, campaigns, deals, commissions, Paystack, and so on) are **out of scope** until their dedicated tasks.

Authoritative product documents live in `../foundational_docs`. This README covers implementation setup only.

## Runtime

| Tool | Verified locally | Notes |
| --- | --- | --- |
| PHP | 8.4.1 | Composer constraint: `^8.3` |
| Composer | 2.8.5 | |
| Laravel | 13.x | TAD backend framework |
| MySQL | 8.0 | TAD system of record |
| Redis | 7.x compatible | Cache, queues, shared sessions |
| Node.js / npm | 20.x / 10.x | Only needed for the default Laravel Vite assets; not required to run the API |

Docker is the TAD-approved way to run MySQL and Redis consistently. This machine did not have Docker installed at bootstrap; `docker-compose.yml` is still provided. Local development can use MAMP MySQL (port `8889`) and a host Redis process.

## Environments

| Name | Role |
| --- | --- |
| `local` | Engineer workstation (`APP_ENV=local`) |
| `testing` | Isolated automated tests (`phpunit.xml` forces SQLite in-memory) |
| `staging` | QA / UAT (example file: `.env.staging.example`) |
| `production` | Live traffic (example file: `.env.production.example`) |

Never commit `.env` files that contain secrets. Copy an example file and fill values locally or via a secret manager.

```bash
cp .env.example .env
php artisan key:generate
```

MAMP on this workstation uses MySQL `127.0.0.1:8889`. If you use MAMP, set `DB_PORT=8889` and the local MySQL credentials in `.env` only.

## Install

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

Create the MySQL database (name `marcaturshub` by default), then:

```bash
php artisan migrate
```

Seeders are intentionally empty. Domain factories and seeders will be added with their features.

Optional Docker dependencies:

```bash
docker compose up -d
```

Then point `.env` at `DB_HOST=127.0.0.1`, `DB_PORT=3306`, and the compose credentials.

## Run the API

```bash
php artisan serve
```

The versioned API is served under `/api/v1`.

Health check (no authentication):

```bash
curl -sS http://127.0.0.1:8000/api/v1/health
```

Laravel also exposes `/up` as a process liveness probe.

## Tests

Automated tests use an isolated SQLite in-memory database. They do not read development or production MySQL data.

```bash
php artisan test
# or
composer test
```

## API testing collection

Import `api-testing/MarcatursHub.postman_collection.json` into Postman (or a compatible runner).

Import an environment from `api-testing/environments/` and set `baseUrl`.

Only the **Health** folder contains a real request. Other folders are placeholders for later tasks and must not be filled with fake endpoints.

Newman (optional):

```bash
npx newman run api-testing/MarcatursHub.postman_collection.json \
  -e api-testing/environments/local.postman_environment.json
```

## Architecture notes

- Modular monolith, REST, URI versioning: `/api/v1/...` (TAD §48).
- Authentication foundation: Laravel Sanctum (session/cookie for the first-party web app). Login and registration are not implemented in this task.
- Authorization: server-side policies/gates will be added with domain features. The error contract already maps `403`.
- MySQL is the system of record. Redis is configured for cache, queues, and shared sessions.
- Private files default to the `local` / `sensitive` disks. Production should use private S3 (`FILESYSTEM_DISK=s3`) once credentials are supplied. The AWS SDK is not installed until the first file-upload task.
- Domain modules listed in the TAD will be introduced when those features are built. Empty module shells were not added here.

See [docs/API.md](docs/API.md) for the response contract, error catalog, and health payload.

## Git

`.env`, vendor, logs, and SQLite files are gitignored. Do not commit keys, passwords, Paystack credentials, or AWS secrets.
