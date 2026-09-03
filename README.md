# MarcatursHub Backend

API-first Laravel service for MarcatursHub (TARIZEFA LIMITED).

This repository is the MarcatursHub API. Deal foundation through payment confirmation, Commission liability, Commission settlement recording, and Commission overdue detection (MH-BE-015–021) are implemented. Commission reminders/dispute, Featured visibility, and customer payments remain out of scope until their dedicated tasks. Platform Paystack is used only for campaign listing extensions (MH-BE-008).

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

Seeders are intentionally empty. Provision a local administrator with `php artisan marcaturs:create-admin` (see Authentication below). Domain factories and seeders for marketplace data will be added with their features.

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

### Local realtime (chat)

Chat REST works without Reverb. To deliver new messages over WebSockets locally, also run:

```bash
php artisan reverb:start
```

Use `BROADCAST_CONNECTION=reverb` and the `REVERB_*` placeholders from `.env.example`. Redis is already required for cache/queues/sessions; this slice does not add Horizon or a chat-specific queue worker (broadcast runs after DB commit, synchronously). Production ALB/WebSocket topology is not configured in this repository.


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

Implemented folders: **Health**, **Authentication**, **Businesses**, **Ambassadors**, **Verification — Participant**, **Verification — Administration**, **Categories**, **Categories — Administration**, **Campaign Marketplace**, **Campaigns — Business**, **Campaign Lifecycle — Administration**, **Campaign Extensions — Business**, **Campaign Extension Packages — Administration**, **Paystack Webhook**, **Campaign Marketing Resources**, **Conversations**, **Deals**. Other folders are placeholders.

Authentication uses collection variables `token`, `businessEmail`, `ambassadorEmail`, `adminEmail`, and `password` (demo values only; not production secrets). Login and register scripts store `token` for Current User and Logout. Do not put real identity documents in Postman.

Newman (optional):

```bash
npx newman run api-testing/MarcatursHub.postman_collection.json \
  -e api-testing/environments/local.postman_environment.json
```

## Architecture notes

- Modular monolith, REST, URI versioning: `/api/v1/...` (TAD §48).
- Authentication: Laravel Sanctum (session/cookie for the first-party web app, plus a bearer token on login/register for API clients). See [docs/API.md](docs/API.md).
- Authorization: `auth:sanctum`, `account.access`, and `role:*` middleware plus Gates `admin`, `business`, `ambassador`.
- MySQL is the system of record. Redis is configured for cache, queues, and shared sessions. Laravel Reverb is the local WebSocket process for chat delivery; it is not the message store.
- Private files default to the `local` / `sensitive` / `campaign_media` disks. Verification evidence and Deal payment evidence use `sensitive`; campaign marketing files use `campaign_media` (`storage/app/private/campaign-media`). Neither is given a public URL. Production may point those disks at private S3 without changing the API.
- Domain modules listed in the TAD will be introduced when those features are built. Empty module shells were not added here.

- Domain notes: [docs/verification.md](docs/verification.md), [docs/categories.md](docs/categories.md), [docs/campaigns.md](docs/campaigns.md), [docs/payments.md](docs/payments.md), [docs/chat.md](docs/chat.md), [docs/deals.md](docs/deals.md).

## Authentication

```bash
# Public roles: BUSINESS or AMBASSADOR
curl -sS -X POST http://127.0.0.1:8000/api/v1/auth/register \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"name":"Ada","email":"ada@example.com","password":"password123","password_confirmation":"password123","role":"BUSINESS"}'

curl -sS -X POST http://127.0.0.1:8000/api/v1/auth/login \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email":"ada@example.com","password":"password123"}'

curl -sS http://127.0.0.1:8000/api/v1/auth/me \
  -H 'Accept: application/json' -H "Authorization: Bearer TOKEN"

curl -sS -X POST http://127.0.0.1:8000/api/v1/auth/logout \
  -H 'Accept: application/json' -H "Authorization: Bearer TOKEN"

php artisan marcaturs:create-admin admin@example.com --name="Platform Admin" --password="choose-a-strong-password"
```

Protected routes use `auth:sanctum` and `account.access`. Restrict a route with `->middleware('role:ADMIN')` (or `BUSINESS` / `AMBASSADOR`).

After registration, complete a profile (not created automatically):

```bash
curl -sS -X POST http://127.0.0.1:8000/api/v1/businesses/me \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer TOKEN" \
  -d '{"legal_name":"Ada Ventures Ltd","operating_location":"Lagos"}'
```

Verification is a separate trust process after profile completion. Participants call `/api/v1/verification/*`. Administrators configure requirements and review submissions under `/api/v1/admin/verification/*`. See [docs/API.md](docs/API.md#verification).

Marketplace categories: public `GET /api/v1/categories`; ADMIN manages `/api/v1/admin/categories`. BUSINESS campaigns: `/api/v1/campaigns` and `/api/v1/campaigns/{id}/versions`. Paid listing extensions: `/api/v1/campaigns/{id}/extensions/*`. Paystack webhook: `POST /api/v1/webhooks/paystack`. See [docs/API.md](docs/API.md#categories).

## Git

`.env`, vendor, logs, and SQLite files are gitignored. Do not commit keys, passwords, Paystack credentials, or AWS secrets.
