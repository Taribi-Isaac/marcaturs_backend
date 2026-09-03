# MarcatursHub API foundation

URI versioning (Technical Architecture Document §48):

```
/api/v1/...
```

The only business-agnostic endpoint in MH-BE-001 is health.

## Health

`GET /api/v1/health`

Unauthenticated. Excluded from API rate limiting and from HTTP maintenance blocking so load balancers can probe it.

Operational database:

- `200` with `data.status` = `ok` or `degraded`
- `503` with `data.status` = `unavailable` when the database cannot be reached

Example (operational):

```json
{
  "success": true,
  "data": {
    "status": "ok",
    "service": "marcaturshub-api",
    "api_version": "v1",
    "timestamp": "2026-09-01T17:00:00+00:00",
    "checks": {
      "application": "ok",
      "database": "ok",
      "cache": "skipped"
    }
  }
}
```

`checks.cache` is `ok`, `unavailable`, or `skipped` (skipped when Redis is not the configured cache/queue backend, including the test suite).

The payload must not include secrets, credentials, `APP_KEY`, database passwords, or dump environment configuration.

## Success envelope

```json
{
  "success": true,
  "data": {},
  "meta": {
    "pagination": {
      "current_page": 1,
      "per_page": 15,
      "total": 0,
      "last_page": 1,
      "from": null,
      "to": null
    }
  }
}
```

`meta` is omitted when unused. Pagination helpers live in `App\Support\Api\ApiResponse`.

## Error envelope

TAD §61 status categories:

| HTTP | Code | Meaning |
| --- | --- | --- |
| 400 | `validation_error` | Request/input validation |
| 401 | `unauthenticated` | Authentication required |
| 403 | `forbidden` | Authorization failure |
| 404 | `not_found` | Missing resource |
| 409 | `conflict` | State conflict |
| 422 | `business_validation` | Business-rule validation |
| 429 | `rate_limited` | Rate limit exceeded |
| 500 | `server_error` | Unexpected failure |
| 503 | `service_unavailable` | Dependency/process unavailable |

```json
{
  "success": false,
  "error": {
    "code": "validation_error",
    "message": "The given data was invalid.",
    "details": {
      "email": ["The email field is required."]
    }
  }
}
```

Input validation uses HTTP 400, not Laravel's default 422. HTTP 422 is reserved for business-rule failures.

## Rate limiting

Named limiters (configurable via environment variables):

- `api`
- `login`
- `registration`
- `password-reset`
- `uploads`

Apply the named limiter when the corresponding route is implemented.

## Authentication

TAD direction: Laravel Sanctum with first-party session cookies for the web app. The same API also issues a Sanctum personal access token on register/login so API clients and Postman can authenticate with `Authorization: Bearer`. JWT is not used.

| Method | Path | Auth | Limiter |
| --- | --- | --- | --- |
| `POST` | `/api/v1/auth/register` | Public | `registration` |
| `POST` | `/api/v1/auth/login` | Public | `login` |
| `POST` | `/api/v1/auth/logout` | Sanctum | `api` |
| `GET` | `/api/v1/auth/me` | Sanctum | `api` |

SPA browsers should still call `GET /sanctum/csrf-cookie` before cookie-based login.

### Registration

Public registration accepts only `BUSINESS` or `AMBASSADOR`. Sending `role=ADMIN` is a validation error and does not create a user.

```json
{
  "name": "Ada Business",
  "email": "ada@example.com",
  "password": "password123",
  "password_confirmation": "password123",
  "role": "BUSINESS"
}
```

Response `201`: `{ user, token, token_type: "Bearer" }`. Password hashes are never returned. Initial `status` is `active`.

### Login

```json
{
  "email": "ada@example.com",
  "password": "password123"
}
```

Unknown email and wrong password both return `401` with `error.code=unauthenticated` and the same message (`Invalid credentials.`). Suspended or banned accounts return `403`. Restricted accounts may log in.

### Current user

`GET /api/v1/auth/me` returns identity fields: `id`, `name`, `email`, `role`, `status`, `last_login_at`, `created_at`. It does not return password, hash, token, or `remember_token`.

### Logout

Revokes the current Sanctum token (if a bearer token was used) and invalidates the web session when present. Unauthenticated logout returns `401`. A second logout with the same token also returns `401`.

### Roles

Stored on `users.role` as a PHP-backed string enum:

- `ADMIN`
- `BUSINESS`
- `AMBASSADOR`

Reusable checks:

- middleware `role:ADMIN` (comma-separate multiple roles)
- `Gate::allows('admin'|'business'|'ambassador')`
- `User::isAdmin()` / `isBusiness()` / `isAmbassador()`

The TAD also lists future staff roles (`SUPER_ADMIN`, `MODERATOR`, `SUPPORT`, `COMPLIANCE_REVIEWER`). Those are not implemented yet. `BUSINESS` maps to the TAD `BUSINESS_USER` actor.

### Account status

Stored on `users.status`:

| Status | Login | Authenticated API |
| --- | --- | --- |
| `active` | Yes | Full (for this layer) |
| `restricted` | Yes | `/me` and `/logout` only |
| `suspended` | No (`403`) | Blocked except `/logout` |
| `banned` | No (`403`) | Blocked except `/logout` |

Enforced by `account.access` middleware. There is no public API to change status in this task.

### Admin provisioning

There is no public register-admin endpoint. Create an administrator with:

```bash
php artisan marcaturs:create-admin admin@example.com --name="Platform Admin" --password="choose-a-strong-password"
```

Production operator provisioning (invite flow, break-glass, Secrets Manager) is not specified in the foundational documents and remains an open decision.

Passwords must be at least 8 characters (`Password::defaults()`). Laravel hashes them with the framework hasher. Do not invent extra complexity rules unless product/security later requires them.

## Profiles

Identity (`User`) and participant profiles are separate. Registration does **not** create a profile. UX/PRD flow: Register → Create/Complete profile → Verification (later).

ADMIN users have no marketplace profile.

| Method | Path | Role | Purpose |
| --- | --- | --- | --- |
| `POST` | `/api/v1/businesses/me` | BUSINESS | Create/complete own profile (`409` if it already exists) |
| `GET` | `/api/v1/businesses/me` | BUSINESS | Retrieve own profile (`404` if not created) |
| `PATCH` | `/api/v1/businesses/me` | BUSINESS | Update own profile |
| `POST` | `/api/v1/ambassadors/me` | AMBASSADOR | Create/complete own profile (`409` if it already exists) |
| `GET` | `/api/v1/ambassadors/me` | AMBASSADOR | Retrieve own profile (`404` if not created) |
| `PATCH` | `/api/v1/ambassadors/me` | AMBASSADOR | Update own profile |

There is no `PATCH /businesses/{id}` participant endpoint. Ownership is the authenticated user.

### Business profile fields

User-editable: `legal_name` (required on create), `trading_name`, `description`, `category` (optional **descriptive text**, not the controlled marketplace taxonomy), `address`, `operating_location`, `contact_email`, `contact_phone`, `website`, `social_links` (object of strings).

Not in this task: payment details, reputation, hard-coded NIN/CAC lists. Verification is a separate module (see below).

### Ambassador profile fields

User-editable: `display_name` (required on create), `profile_description`, `location`, `skills` (string array), `marketing_interests` (string array), `experience`.

Not in this task: profile image uploads, certification, achievements, reputation, campaign history.

Profile updates cannot change User `role` or `status`. Restricted/suspended/banned accounts follow MH-BE-002 `account.access` rules (restricted users cannot call profile or verification endpoints).

## Verification

Verification is distinct from registration and from profile completion. `VERIFIED` means only that MarcatursHub completed the administrator-defined checks for that participant. It is not an endorsement, product-quality guarantee, payment guarantee, or legal certification.

Administrators define requirements per participant type (`BUSINESS` or `AMBASSADOR`). ADMIN accounts have no marketplace verification workflow. Requirements are not hard-coded (no permanent NIN/CAC list). Active required requirements determine overall status; optional requirements never block `VERIFIED`. If there are no active required requirements, overall status is `NOT_STARTED` (not vacuously verified).

Submit requires a completed Business or Ambassador profile (`422 business_validation` otherwise).

### Overall status

Computed from **active required** requirements (not stored on `users`):

| Status | Meaning |
| --- | --- |
| `NOT_STARTED` | No required submissions yet, or no active required requirements |
| `PENDING` | At least one required item submitted and none blocking at a later state |
| `UNDER_REVIEW` | At least one required item is under review |
| `MORE_INFORMATION_REQUIRED` | At least one required item needs more information |
| `REJECTED` | At least one required item is rejected (unless more information is also present) |
| `VERIFIED` | Every active required requirement is approved |

Submission statuses: `pending`, `under_review`, `approved`, `rejected`, `more_information_required`.

### Participant endpoints

Role: `BUSINESS` or `AMBASSADOR`. Auth: Sanctum + `account.access`. Uploads use limiter `uploads`.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/verification/requirements` | Active requirements for the caller's participant type |
| `GET` | `/api/v1/verification/status` | Overall status plus own submissions |
| `POST` | `/api/v1/verification/submissions` | Create a submission (`409` if one already exists) |
| `PATCH` | `/api/v1/verification/submissions/{id}` | Resubmit when `rejected` or `more_information_required` |

Create body (JSON or multipart): `requirement_id`, `text_value` (text/email/phone/other), `evidence` (file for `document` type). Allowed evidence: pdf, jpeg, jpg, png, webp; size from `VERIFICATION_MAX_EVIDENCE_KB` (default 5120).

Participants never receive: `reviewer_notes`, disk/path, public file URLs, other users' submissions.

### Administration endpoints

Role: `ADMIN`.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/admin/verification/requirements` | All requirement definitions (including inactive) |
| `POST` | `/api/v1/admin/verification/requirements` | Create a requirement |
| `PATCH` | `/api/v1/admin/verification/requirements/{id}` | Update a requirement |
| `GET` | `/api/v1/admin/verification/submissions` | Paginated list (`?status=`) |
| `GET` | `/api/v1/admin/verification/submissions/{id}` | Review payload |
| `POST` | `/api/v1/admin/verification/submissions/{id}/start-review` | `pending` → `under_review` |
| `POST` | `/api/v1/admin/verification/submissions/{id}/approve` | Approve current version |
| `POST` | `/api/v1/admin/verification/submissions/{id}/reject` | Reject (`reason` required) |
| `POST` | `/api/v1/admin/verification/submissions/{id}/request-information` | Request more information (`reason` required) |
| `GET` | `/api/v1/admin/verification/submissions/{id}/events` | Audit history |
| `GET` | `/api/v1/admin/verification/submissions/{id}/evidence/{evidence}/download` | Authenticated private download |

There is no public verification badge or directory in this task. An admin UI for requirement configuration is deferred; these APIs are the backend foundation.

### Security and compliance

Evidence is stored on the private `sensitive` disk (`verification/{userId}/{submissionId}/{uuid}.ext`). Resubmission creates a new version and review event; previous versions remain. Automated NIN/CAC vendors, OCR, KYC scoring, and public S3 URLs are out of scope. Retention days may be set via `VERIFICATION_RETENTION_DAYS`; no purge job is implemented. Final legal/compliance policy is pending external counsel — do not treat a document type as legally mandatory unless product/legal later says so.

A dedicated admin UI is deferred to a frontend task.

## Categories

Marketplace categories are administrator-defined (Source of Truth §16, BRD FR-039). There is no category tree and no hard-coded sector list in code. Suggested names such as Solar or Education may be created by an administrator after legal/compliance review; they are not seeded.

`listing_status`:

| Value | Meaning in this task |
| --- | --- |
| `allowed` | Ordinary listing category |
| `restricted` | Visible and assignable; extra verification/approval rules are deferred |
| `prohibited` | Hidden from the public list; cannot be assigned to new campaigns |

`is_active` is a separate on/off switch. Inactive categories are hidden from `GET /api/v1/categories` and cannot be assigned to new campaigns. Existing campaign rows keep their foreign key (categories are not deleted).

Business profile `category` remains optional free text. Campaigns use `category_id` against this table.

| Method | Path | Auth | Role | Purpose |
| --- | --- | --- | --- | --- |
| `GET` | `/api/v1/categories` | Public | — | Active, non-prohibited categories |
| `GET` | `/api/v1/admin/categories` | Sanctum | ADMIN | All categories including inactive/prohibited |
| `POST` | `/api/v1/admin/categories` | Sanctum | ADMIN | Create |
| `PATCH` | `/api/v1/admin/categories/{id}` | Sanctum | ADMIN | Update, including activate/deactivate and listing status |

BUSINESS and AMBASSADOR cannot mutate categories (`403`).

## Campaign foundation

Campaign ≠ Deal. The campaign **shell** (MH-BE-005) holds ownership, category, and lifecycle status. **Commercial terms are versioned** (MH-BE-006 / EDP ENG-013).

A BUSINESS user with a completed profile can create a **draft campaign** owned by the authenticated user. Ownership is never taken from the request body. Ambassadors and admins cannot create business campaigns through these routes. Other businesses cannot read or patch another user's campaign (`403`).

Allowed campaign statuses are driven by **explicit lifecycle actions** (MH-BE-007). Do not `PATCH status`. Publishing a version still does **not** activate the campaign.

| Method | Path | Auth | Role | Purpose |
| --- | --- | --- | --- | --- |
| `GET` | `/api/v1/campaigns` | Sanctum | BUSINESS | List own campaigns |
| `POST` | `/api/v1/campaigns` | Sanctum | BUSINESS | Create draft (`title`, `category_id`) |
| `GET` | `/api/v1/campaigns/{id}` | Sanctum | BUSINESS | Show own campaign |
| `PATCH` | `/api/v1/campaigns/{id}` | Sanctum | BUSINESS | Update own **draft** shell |
| `POST` | `/api/v1/campaigns/{id}/submit` | Sanctum | BUSINESS | draft → submitted (published version required) |
| `POST` | `/api/v1/campaigns/{id}/deactivate` | Sanctum | BUSINESS | active/expiring → deactivated |
| `GET` | `/api/v1/campaigns/{id}/versions` | Sanctum | BUSINESS | List versions |
| `POST` | `/api/v1/campaigns/{id}/versions` | Sanctum | BUSINESS | Create the next draft version |
| `GET` | `/api/v1/campaigns/{id}/versions/{n}` | Sanctum | BUSINESS | Show version `n` |
| `PATCH` | `/api/v1/campaigns/{id}/versions/{n}` | Sanctum | BUSINESS | Update a **draft** version |
| `POST` | `/api/v1/campaigns/{id}/versions/{n}/publish` | Sanctum | BUSINESS | Freeze a complete draft snapshot |
| `GET` | `/api/v1/admin/campaigns` | Sanctum | ADMIN | List campaigns (`?status=`) |
| `GET` | `/api/v1/admin/campaigns/{id}` | Sanctum | ADMIN | Show any campaign |
| `POST` | `/api/v1/admin/campaigns/{id}/approve` | Sanctum | ADMIN | submitted → approved |
| `POST` | `/api/v1/admin/campaigns/{id}/reject` | Sanctum | ADMIN | submitted → draft (`reason`) |
| `POST` | `/api/v1/admin/campaigns/{id}/request-modification` | Sanctum | ADMIN | submitted → draft (`reason`) |
| `POST` | `/api/v1/admin/campaigns/{id}/activate` | Sanctum | ADMIN | approved → active; sets listing window |
| `POST` | `/api/v1/admin/campaigns/{id}/suspend` | Sanctum | ADMIN | active/expiring → suspended (`reason`) |
| `POST` | `/api/v1/admin/campaigns/{id}/close` | Sanctum | ADMIN | close without delete |

Scheduler: `campaigns:process-lifecycle` every 15 minutes. Default `CAMPAIGN_EXPIRING_LEAD_DAYS=0` skips EXPIRING.

## Campaign paid extension

Packages and prices are administrator-configurable (PRD 8.24 / 20.1). Amounts are integer minor units (kobo) in `NGN`.

**Published version ≠ active campaign ≠ paid extension.**

| Method | Path | Auth | Role | Purpose |
| --- | --- | --- | --- | --- |
| `GET` | `/api/v1/campaigns/{id}/extension-packages` | Sanctum | BUSINESS owner | Active packages |
| `GET` | `/api/v1/campaigns/{id}/extensions` | Sanctum | BUSINESS owner | Applied extension history |
| `POST` | `/api/v1/campaigns/{id}/extensions/initialize` | Sanctum | BUSINESS owner | Create pending Paystack payment (`package_id`) |
| `POST` | `/api/v1/campaigns/{id}/extensions/verify` | Sanctum | BUSINESS owner | Server-side verify + apply (`reference`) |
| `GET` | `/api/v1/admin/campaign-extension-packages` | Sanctum | ADMIN | All packages |
| `POST` | `/api/v1/admin/campaign-extension-packages` | Sanctum | ADMIN | Create package |
| `PATCH` | `/api/v1/admin/campaign-extension-packages/{id}` | Sanctum | ADMIN | Update package (including deactivate) |
| `GET` | `/api/v1/admin/campaigns/{id}/extensions` | Sanctum | ADMIN | Extension history for any campaign |
| `POST` | `/api/v1/webhooks/paystack` | Paystack signature | — | Confirm platform payment |

Eligible campaign states: `active`, `expiring`, `expired`. See [docs/campaigns.md](campaigns.md) and [docs/payments.md](payments.md).

## Campaign marketplace discovery

Public listing for ambassadors and guests (PRD §21). Uses existing lifecycle states; no extra visibility column. Owner `/api/v1/campaigns` remains private.

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| `GET` | `/api/v1/marketplace/campaigns` | Public | Paginated discoverable campaigns |
| `GET` | `/api/v1/marketplace/campaigns/{id}` | Public | Public detail for a discoverable campaign; otherwise `404` |

Query parameters: `q`, `category_id`, `commission_type`, `service_area`, `status` (`active`\|`expiring`), `verified`, `price_min`, `price_max`, `page`, `per_page` (default 15, max 100). Sort is newest listing first. See [docs/campaigns.md](campaigns.md).

## Campaign marketing resources

Files are Campaign-scoped. Downloads are never public object URLs.

| Method | Path | Auth | Role | Purpose |
| --- | --- | --- | --- | --- |
| `GET` | `/api/v1/campaigns/{id}/resources` | Sanctum | BUSINESS owner | List |
| `POST` | `/api/v1/campaigns/{id}/resources` | Sanctum | BUSINESS owner | Upload (`type`, `title`, `file`, optional `description`, `sort_order`) |
| `GET` | `/api/v1/campaigns/{id}/resources/{rid}` | Sanctum | BUSINESS owner | Show metadata |
| `PATCH` | `/api/v1/campaigns/{id}/resources/{rid}` | Sanctum | BUSINESS owner | Update metadata and/or replace file |
| `DELETE` | `/api/v1/campaigns/{id}/resources/{rid}` | Sanctum | BUSINESS owner | Delete file and row |
| `GET` | `/api/v1/campaigns/{id}/resources/{rid}/download` | Sanctum | BUSINESS owner | Stream file |
| `GET` | `/api/v1/marketplace/campaigns/{id}/resources/{rid}/download` | Sanctum | AMBASSADOR | Stream if campaign is discoverable |
| `GET` | `/api/v1/admin/campaigns/{id}/resources` | Sanctum | ADMIN | List |
| `GET` | `/api/v1/admin/campaigns/{id}/resources/{rid}/download` | Sanctum | ADMIN | Stream |

Public marketplace detail includes `marketing_resources` metadata (no storage path). Uploads use limiter `uploads`.

## Business–Ambassador chat

Conversations are Business–Ambassador communication only (**one thread per pair**). They are not Campaign-scoped and do not require a Campaign or Deal. See [docs/chat.md](chat.md).

Sending a message remains `POST /api/v1/conversations/{id}/messages` (`201`). After persist, the API attempts a private WebSocket event `message.created` on `conversation.{id}`. Channel auth: `POST /api/broadcasting/auth` with Sanctum (`auth:sanctum` + `account.access`). Broadcast failure does not fail the REST write. Local Reverb: `php artisan reverb:start`. Production WebSocket/ALB topology is pending.

| Method | Path | Auth | Role | Purpose |
| --- | --- | --- | --- | --- |
| `GET` | `/api/v1/conversations` | Sanctum | BUSINESS, AMBASSADOR | List own conversations |
| `POST` | `/api/v1/conversations` | Sanctum | BUSINESS, AMBASSADOR | Open unique pair (`201` created, `200` existing) |
| `GET` | `/api/v1/conversations/{id}` | Sanctum | Participant | Show |
| `GET` | `/api/v1/conversations/{id}/messages` | Sanctum | Participant | Paginated history |
| `POST` | `/api/v1/conversations/{id}/messages` | Sanctum | Participant | Send text (`content`) |
| `POST` | `/api/v1/conversations/{id}/read` | Sanctum | Participant | Set `read_at` on inbound messages |
| `POST` | `/api/v1/conversations/{id}/report` | Sanctum | Participant | Report once (`reason`) |
| `GET` | `/api/v1/admin/conversations` | Sanctum | ADMIN | Reported conversations |
| `GET` | `/api/v1/admin/conversations/{id}` | Sanctum | ADMIN | Reported conversation |
| `GET` | `/api/v1/admin/conversations/{id}/messages` | Sanctum | ADMIN | Reported message history |

Business body: `{ "ambassador_id": 2 }`. Ambassador body: `{ "business_id": 1 }`. `campaign_id` is rejected. Reopening returns `200`.

## Deals

A Deal is a versioned commercial record (Ambassador + Business + Campaign + Campaign Version). Not a customer account and not Chat. See [docs/deals.md](deals.md).

| Method | Path | Auth | Role | Purpose |
| --- | --- | --- | --- | --- |
| `POST` | `/api/v1/deals` | Sanctum | AMBASSADOR | Create (`campaign_id`; optional `expected_transaction_amount`) |
| `GET` | `/api/v1/deals` | Sanctum | BUSINESS, AMBASSADOR | List own Deals |
| `GET` | `/api/v1/deals/{id}` | Sanctum | Party | Show + Deal events |
| `POST` | `/api/v1/deals/{id}/payment-evidence` | Sanctum | Deal AMBASSADOR | Submit evidence (`kind` + file/reference). Not confirmation. |
| `GET` | `/api/v1/deals/{id}/payment-evidence` | Sanctum | Party | List evidence |
| `GET` | `/api/v1/deals/{id}/payment-evidence/{evidence}` | Sanctum | Party | Show evidence metadata |
| `GET` | `/api/v1/deals/{id}/payment-evidence/{evidence}/download` | Sanctum | Party | Private file download |
| `POST` | `/api/v1/deals/{id}/confirm` | Sanctum | Deal BUSINESS | Seal Deal (`confirmed_payment_amount` required for percentage) |
| `POST` | `/api/v1/deals/{id}/payment-evidence/{evidence}/reject` | Sanctum | Deal BUSINESS | Reject evidence (`reason` required) |
| `GET` | `/api/v1/commissions` | Sanctum | BUSINESS, AMBASSADOR | List own Commission liabilities |
| `GET` | `/api/v1/commissions/{id}` | Sanctum | Party | Show Commission |
| `POST` | `/api/v1/commissions/{id}/mark-paid` | Sanctum | BUSINESS owner | `due → paid` (optional reference/note) |
| `POST` | `/api/v1/commissions/{id}/confirm-received` | Sanctum | AMBASSADOR payee | `paid → received` |

New Deals only for `active` or `expiring` campaigns with a published current version. Status starts at `payment_pending`. Confirmation seals to `sealed`, creates one Commission (`UNIQUE deal_id`), and writes `payment_confirmed`, `deal_sealed`, `commission_due`. Settlement recording is `due → paid → received` on the Commission (MH-BE-020). `is_overdue` (derived: `status = due AND now > due_at`) is exposed on Commission responses (MH-BE-021). Overdue detection writes a one-time `commission_overdue` audit event; no notifications or reminders. Payout, disputes, Deal completion, and Chat integration are not in this slice.

## Notifications

In-app notification foundation (MH-BE-022C). Notifications are persisted via Laravel's `database` channel with idempotency. Email delivery uses the configured Laravel mail driver.

| Method | Path | Auth | Role | Purpose |
| --- | --- | --- | --- | --- |
| `GET` | `/api/v1/notifications` | Sanctum | Any authenticated | List own notifications (paginated, newest first) |
| `GET` | `/api/v1/notifications/{id}` | Sanctum | Owner | Show own notification |
| `POST` | `/api/v1/notifications/{id}/read` | Sanctum | Owner | Mark notification as read (idempotent) |

The notification API is scoped to the authenticated user's own notifications only. Cross-user access returns `404`. Restricted/suspended/banned accounts are blocked by the `account.access` middleware. Commission reminders, notification preferences, deletion, and mark-all-read are deferred to MH-BE-022D+.

See [docs/categories.md](categories.md) and [docs/campaigns.md](campaigns.md).



