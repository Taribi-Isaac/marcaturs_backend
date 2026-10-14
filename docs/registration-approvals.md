# Registration approvals — controlled pilot access (MH-OPS-031)

Backend foundation for the owner decision recorded in MH-OPS-030: pilot registration uses a **pre-approved email + role allow-list** (option B1), managed by **SUPER_ADMIN only**, with **mandatory role binding**.

**Status:** implemented but **not active**. The default mode is `open`, so deploying this code does not change registration behaviour. Production activation is a separate, explicitly authorized configuration change.

## Basis

- PRD §25 Release 0 — Internal Alpha: "small number of businesses, controlled ambassador group" before public acquisition.
- PRD §30 and Source of Truth §15: controlled ambassador acquisition; seed businesses before broad ambassador acquisition.
- BRD FR-001: the system allows **eligible** participants to create accounts (eligibility is defined operationally by the approval list during the pilot).
- PRD INV-010, EDP §39, BRD NFR-003: administrative actions are auditable (who, what, when, object, previous state, new state).
- BRD §17: fake-account controls include manual review.
- No foundational document requires invite tokens or invitation emails; this is deliberately not B2.

## Registration modes

| `REGISTRATION_MODE` | Behaviour |
| --- | --- |
| `open` (default when unset) | Existing behaviour: any valid BUSINESS or AMBASSADOR registration succeeds. The allow-list is ignored and pending approvals are not touched. |
| `allowlist` | Registration additionally requires a **pending** approval whose email and role both match the request. |
| anything else (including empty) | Treated as `allowlist` (fail closed). |

The mode is read from `config('registration.mode')` (`config/registration.php`). In production the config is cached, so a change requires editing `shared/.env`, rebuilding `config:cache` and reloading PHP-FPM — an operational change that must be separately authorized. The mode is enforced only in the backend registration path; hiding the frontend form is not a control.

## Email and role binding

- Emails are normalized exactly like `RegisterRequest`: trim + lowercase. No provider-specific rewriting (plus-addressing and dots are significant).
- Each approval binds one email to exactly one role: `BUSINESS` or `AMBASSADOR`. A BUSINESS approval never authorizes AMBASSADOR registration, and vice versa.
- `ADMIN` and staff roles (`SUPER_ADMIN`, `OPERATIONS`, …) cannot be approved and cannot be requested at registration (`400`). Admin staff continue to use staff invitations or `marcaturs:create-admin`.

## Lifecycle (single-use)

```text
pending ──(successful registration)──▶ consumed
   │
   └──(SUPER_ADMIN revoke)──▶ revoked
```

- **Single-use.** `users.email` is unique and there is no user-deletion path, so an email can only ever register once; the approval records that use (`consumed_at`, `consumed_by_user_id`). This matches the single-use staff-invitation precedent (MH-BE-045).
- **No expiry.** Approvals stay pending until used or revoked. Expiry was not required and is deferred.
- **Revoke** applies to pending approvals only. A consumed approval cannot be revoked; the resulting account is managed with the existing Admin Users status actions (restrict/suspend/ban). Revoking never deletes the record.
- **One non-revoked approval per email.** A new approval for the same email (any role) is rejected while one is pending or consumed. After revocation the email may be approved again (e.g. with a corrected role).
- An approval cannot be created for an email that already has an account.
- Records are never deleted by the application (audit retention).

## Registration enforcement (allow-list mode)

Order of checks for `POST /api/v1/auth/register`:

1. `throttle:registration` (unchanged, 5/min/IP by default).
2. Existing validation (`RegisterRequest`): name, email format, **duplicate email → `400`**, password policy, role ∈ {BUSINESS, AMBASSADOR}.
3. In one database transaction:
   1. Lock the pending approval for the normalized email (`SELECT … FOR UPDATE` on the unique `active_email` row).
   2. Reject unless it exists and its role equals the requested role.
   3. Mark it consumed with a guarded update (`… WHERE status = 'pending'`); reject if no row changed (lost race).
   4. Create the user (unchanged attributes: `status=active`, unverified email).
   5. Link `consumed_by_user_id` and write the `consumed` audit event.
4. Only after commit: send the email-verification notification and establish the session/token (unchanged).

Any failure inside step 3 rolls back both the user insert and the approval consumption. Rejected requests create no user, no token, no session and send no email.

### Rejection response

```json
{
  "success": false,
  "error": {
    "code": "forbidden",
    "message": "Registration is currently limited to approved participants. This email address is not approved for the selected account type."
  }
}
```

HTTP `403`. The response is identical for an unknown email, a wrong-role attempt, and a revoked or used approval, so it does not reveal whether someone else's email is on the list. (The pre-existing duplicate-email validation error is unchanged and still reveals that an account exists.)

A log line `registration.approval_required_rejected` is written with the requested role only (no email, no IP in context).

## Administration API

All routes: `auth:sanctum` → `account.access` → `email.verified` → `role:ADMIN` → `admin.origin` → `permission:registration_approvals.manage`. The service layer re-checks the permission. The permission is granted to `SUPER_ADMIN` only (`AdminPermissionMatrix`); OPERATIONS, VERIFICATION, MODERATION, participants and guests are refused (`403`/`401`). Restricted, suspended and banned accounts are refused by `account.access`.

| Method | Path | Body / query | Result |
| --- | --- | --- | --- |
| `GET` | `/api/v1/admin/registration-approvals` | `status`, `role`, `q` (email contains), `page`, `per_page` ≤ 100 | Paginated list, newest first |
| `POST` | `/api/v1/admin/registration-approvals` | `email`, `role` (`BUSINESS`\|`AMBASSADOR`), `note?` (≤ 500) | `201` approval |
| `GET` | `/api/v1/admin/registration-approvals/{id}` | — | Approval + up to 50 events (newest first) |
| `POST` | `/api/v1/admin/registration-approvals/{id}/revoke` | `reason?` (≤ 1000) | Revoked approval |

Errors: `400 validation_error` (invalid email/role/note, existing account, existing pending or consumed approval for the email — including a concurrent duplicate caught by the unique index); `422 business_validation` (revoking a consumed or already-revoked approval); `404` unknown id.

Approval resource fields: `id`, `email`, `role`, `status`, `note`, `created_by {id,name}`, `created_at`, `revoked_by {id,name}`, `revoked_at`, `consumed_by_user_id`, `consumed_at`; detail adds `events[]` (`action`, `previous_status`, `new_status`, `reason`, `actor {id,name}`, `created_at`). The internal `active_email` column is not exposed.

No invitation email is sent. The administrator tells the approved participant out of band to register at the normal registration page with that email and role.

## Schema

`registration_approvals`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint PK | |
| `email` | varchar(255) | normalized |
| `active_email` | varchar(255) NULL, **unique** | `= email` while pending or consumed; `NULL` when revoked. Enforces one non-revoked approval per email on MySQL and SQLite without partial indexes |
| `role` | varchar(32) | BUSINESS \| AMBASSADOR |
| `status` | varchar(32) | pending \| consumed \| revoked |
| `note` | varchar(500) NULL | admin context |
| `created_by_user_id` | FK users, restrict on delete | |
| `revoked_by_user_id`, `revoked_at` | FK users NULL (null on delete), timestamp NULL | |
| `consumed_by_user_id`, `consumed_at` | FK users NULL (null on delete), timestamp NULL | |
| `created_at`, `updated_at` | timestamps | |

Indexes: `registration_approvals_active_email_unique`, `registration_approvals_email_idx`, `registration_approvals_status_id_idx`.

`registration_approval_events` (append-only)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint PK | |
| `registration_approval_id` | FK registration_approvals, restrict on delete | |
| `actor_user_id` | FK users, restrict on delete | SUPER_ADMIN for created/revoked; the new user for consumed |
| `action` | varchar(32) | created \| revoked \| consumed |
| `previous_status`, `new_status` | varchar(32) NULL | |
| `reason` | text NULL | revoke reason |
| `meta` | json NULL | `{role}` on create; never the email |
| `created_at`, `updated_at` | timestamps | |

Indexes: `registration_approval_events_approval_id_idx` (approval, id), `registration_approval_events_actor_id_idx` (actor, id), `registration_approval_events_action_idx`.

## Audit and privacy

- Every create, revoke and consume writes one `registration_approval_events` row in the same transaction as the state change (who, what, when, object, previous/new state, reason/metadata — EDP §39).
- The email is stored once, on the approval (required for matching). Events reference the approval by id and do not copy the email. Application logs never include the email.
- Responses never include passwords, tokens or the internal `active_email` column.
- A change of `REGISTRATION_MODE` is an environment change, not an in-app action, so it is not recorded in this table; it is controlled by the deployment approval process (see Deferred).

## Activation checklist (for a later, separately authorized task)

1. Deploy this code (adds two tables via the normal migration step; no behaviour change while `open`).
2. As SUPER_ADMIN, create approvals for the pilot participants.
3. Set `REGISTRATION_MODE=allowlist` in `shared/.env`, rebuild `config:cache`, reload PHP-FPM.
4. Verify: unapproved registration → `403`; approved email + correct role → `201`; wrong role → `403`.
5. Read-only check that no unexpected participant accounts were created between deployment and activation (accounts created while `open` are not affected by the allow-list).

## Deliberately not implemented

- Invitation tokens, invitation emails or an acceptance page (B2).
- Approval expiry, bulk import, automation, or an Admin Control UI.
- A public endpoint exposing the current registration mode.
- In-app (database-backed) registration-mode switching and its audit trail.
- Terms/privacy acceptance at registration (BRD FR-001; open owner decision).
- Changes to email verification, account status, bearer-token policy, sessions, MFA or admin password reset.
