# Authentication token lifecycle policy (MH-GATE-008 / MH-BE-047)

**EDP:** ENG-038  
**Findings closed:** SEC-001 (PAT expiry), SEC-002 (login token accumulation)  
**Status:** **Implemented** by **MH-BE-047** (policy approved in MH-GATE-008)

This policy applies to Laravel Sanctum authentication for MarcatursHub participant SPA, Admin Control SPA, and Bearer PAT consumers. It does **not** introduce JWT, OAuth, refresh tokens, MFA, or a second auth system.

---

## 1. Dual credentials model (current architecture)

MarcatursHub uses **two related credentials** under Sanctum:

| Credential | How established | Primary consumers |
| --- | --- | --- |
| **Web session cookie** | Login/register with stateful API (`statefulApi` + CSRF) | Participant SPA, Admin Control (`credentials: 'include'`) |
| **Personal Access Token (PAT)** | `createToken('auth')` returned as Bearer on login/register by **non-browser Business / Ambassador** clients only (MH-SEC-001 / MH-SEC-012) | Participant API tooling, tests, future mobile |

First-party SPAs authenticate with **session cookies** only. Since MH-SEC-001 their login/register responses contain no PAT (see §7). **Admin accounts never receive a PAT** (MH-SEC-012); Admin API authorization uses the browser session only. Participant PATs must still be purpose-bound and time-bounded because they are issued to tooling, can be copied, and may be used with `Authorization: Bearer` on non-Admin routes.

Architecture references: TAD §32 / ADR-007 (Sanctum/session; no JWT for first-party web).

---

## 2. Authoritative decisions

### 2.1 PAT lifetime (SEC-001)

```text
PAT lifetime = 7 days
```

- Sanctum `expiration` is **10080 minutes** via `SANCTUM_TOKEN_EXPIRATION_MINUTES` (default `60 * 24 * 7`).
- Expired PATs fail authentication (`401`).
- Do **not** use non-expiring PATs.
- Do **not** introduce refresh tokens.

**Rationale:** Bounds stolen-Bearer exposure. SPAs continue to rely on the separate session cookie lifetime (`SESSION_LIFETIME`, default 120 minutes idle). A 1-hour PAT would not improve SPA UX and would punish Bearer clients; 30 days widens compromise windows without product benefit at MVP.

### 2.2 Multiple sessions

| Layer | Policy |
| --- | --- |
| **Cookie sessions** | Multiple simultaneous browser/device sessions are **allowed**. No device inventory UI in this phase. |
| **PATs** | At most **one** active PAT per user after login or registration (see rotation). |

No maximum cookie-session count is enforced in MH-BE-047.

### 2.3 Token rotation on login / registration (SEC-002)

On successful **login** and **registration**:

1. Revoke **all** existing Sanctum PATs for the user.
2. Create a PAT only when **all** of the following hold: the request is non-browser (no Sanctum stateful session), and the account is **Business or Ambassador**. First-party SPA requests create **no** PAT (MH-SEC-001, §7). **Admin** accounts create **no** PAT even on non-browser login (MH-SEC-012, §7).
3. Establish/regenerate the **current** web session when a session is present (existing behaviour).

Other browsers’ cookie sessions are **not** bulk-invalidated on login (no device-management product). Their PATs (if any) are revoked by step 1; cookie sessions continue until idle expiry or logout on that browser.

### 2.4 Token limit

```text
Maximum active PATs per user after login/register = 1
```

Enforced by revoke-all-then-create, not by a separate soft-cap table.

### 2.5 Logout

Preserve the existing API contract for `POST /api/v1/auth/logout`:

1. Revoke **all** Sanctum PATs for the user.
2. Invalidate the **current** web session (when present) and regenerate CSRF.
3. Token invalidation is immediate for revoked PATs.

**Not in MH-BE-047 unless trivial:** bulk-destroying *other* browsers’ session rows. Residual cookie sessions on other devices expire via `SESSION_LIFETIME`. Documented residual risk; full “logout everywhere” for cookies is a later product task if required.

### 2.6 Password reset

1. Revoke **all** Sanctum PATs.
2. Delete all rows in the `sessions` table for that `user_id` when the table exists (invalidates persisted browser sessions regardless of the active `SESSION_DRIVER` for the current request).
3. Do not auto-login after reset (existing participant SPA behaviour).
4. Do not weaken anti-enumeration messaging.

### 2.7 Change password (authenticated)

Preserve MH-BE-037 behaviour:

1. Keep the **current** PAT (if Bearer) and **current** web session.
2. Revoke **all other** PATs.
3. Do **not** regenerate the SPA session cookie id on change-password (avoids concurrent-request 401 races documented in frontend auth docs).

### 2.8 Account status

| Status | Token / session policy | Access enforcement |
| --- | --- | --- |
| `restricted` | Keep PATs and sessions | `EnsureAccountAccess` allow-list only |
| `suspended` | Revoke **all** PATs (already) | Block login + product APIs except logout |
| `banned` | Revoke **all** PATs (already) | Same as suspended |
| `restored` | No automatic new token; user logs in normally | Middleware allows per new status |

Do not duplicate middleware checks inside token issuance beyond existing `canAuthenticate()` on login.

---

## 3. Non-goals (explicit)

MH-BE-047 must **not**:

- Add refresh tokens, JWT, OAuth, MFA, or passkeys
- Redesign login/password-reset UX
- Build device/session management UI
- Change Reverb, CORS, security headers, or S3
- Log plaintext tokens, passwords, or reset tokens

---

## 4. Frontend / UX implications

| Client | Impact |
| --- | --- |
| Participant SPA | Auth is cookie + CSRF only; login/register responses carry no PAT (MH-SEC-001). Idle session still follows `SESSION_LIFETIME`. |
| Admin Control | Same as participant. Change-password continues without forced re-login. |
| Bearer-only clients | Must re-authenticate after 7 days or after login/register/logout/reset/suspend/ban that revokes PATs. |
| 401 handling | Existing “401 → treat as logged out” remains correct for expired/revoked credentials. |

No new frontend screens are required for MH-BE-047. Optional later: surface “session expired” copy if product wants clearer messaging (not blocking).

---

## 5. Implementation status (MH-BE-047)

Implemented:

1. `config/sanctum.php` `expiration` → `SANCTUM_TOKEN_EXPIRATION_MINUTES` default **10080**.
2. `AuthenticationService::establishSession`: under a user row lock, revoke all PATs then `createToken('auth')`.
3. `PasswordResetService::reset`: revoke PATs and delete `sessions` rows for the user.
4. Logout / change-password / suspend-ban behaviours preserved.
5. API and frontend auth docs updated.

---

## 6. Test contract (MH-BE-047)

### PAT lifetime

- Fresh PAT authenticates before expiry.
- PAT older than lifetime is rejected on authenticated routes (`401`).

### Rotation

- Non-browser login creates one PAT and leaves zero prior PATs; SPA (stateful) login leaves zero PATs (MH-SEC-001).
- Registration follows the same rule.
- A previous Bearer token fails after a new login.

### Logout

- Logout revokes all PATs; subsequent Bearer use is `401`.
- Current web session cannot continue after logout.

### Password reset

- After successful reset, all prior PATs are invalid.
- Database sessions for that user cannot continue (when driver is `database`).

### Change password

- Current credential remains usable; other PATs are revoked.

### Account status

- Suspended/banned: PATs revoked; login blocked; middleware behaviour unchanged for restricted/restored.

### Regression

- Full `php artisan test` green; `vendor/bin/pint --dirty` clean.

---

## 7. Browser sessions, Bearer clients and the Admin origin boundary (MH-SEC-001 / MH-SEC-012)

### 7.1 The weaknesses that were closed

Before MH-SEC-001:

1. Every login/register returned a 7-day PAT in the JSON body, including responses to the two SPAs, which never use it. Page script on the calling origin could read it.
2. `EnsureAdminOrigin` (Option A) only checked cookie-authenticated requests. A request with `Authorization: Bearer <PAT>` skipped the origin check entirely.

Together: if script ran on `app.*` (for example injected through participant content) while an Admin signed in there, it could read the Admin PAT from the login response. The participant SPA's automatic logout of Admin accounts runs only after that response. Script could then call `/api/v1/admin/*` from `app.*` with the token. CORS allows `app.*` with any header, and the shared `XSRF-TOKEN` cookie is readable there, so neither stopped it. Option A's purpose, keeping Admin requests unusable from the participant origin, was bypassed.

MH-SEC-001 stopped issuing PATs to first-party SPAs and origin-bound cookie Admin requests. A remaining gap (MH-SEC-010 S7) was that a **valid Admin PAT with no `Origin`** could still authorize Admin APIs. Product/ops confirmed Admin bearer tooling is not used. **MH-SEC-012** closed that gap by rejecting all non-session credentials on Admin routes and stopping Admin PAT issuance.

### 7.2 Model now enforced

| Client | How it is recognised | Credential | Admin routes (`/api/v1/admin/*`) | Non-Admin API |
| --- | --- | --- | --- | --- |
| Admin Control (browser) | Stateful Sanctum session → `TransientToken` | HttpOnly session cookie + CSRF. **No PAT.** | Allowed only when `Origin` is in `security.admin_origins` and role/permission/account-status pass | N/A (Admin uses Admin Control) |
| Participant SPA (browser) | Stateful Sanctum session → `TransientToken` | HttpOnly session cookie + CSRF. **No PAT.** | Denied (`role:ADMIN` / origin) | Allowed per role and middleware |
| Any client with an Admin PAT (including historical tokens) | `Authorization: Bearer` → personal access token (not `TransientToken`) | PAT | **Always denied** (`403` `forbidden`), whether `Origin` is absent, an allowed Admin origin, or disallowed | Not a supported Admin Control path. Login/register for Admin no longer issues a PAT. A historical Admin PAT row may still authenticate identity on non-Admin routes until revoked/expired; it must not authorize Admin APIs. |
| Non-browser Business / Ambassador | No stateful session; Bearer PAT from login/register | PAT (7-day) | Denied by role | Allowed where participant Bearer auth is supported |

**Browser session vs PAT:** Sanctum attaches a `TransientToken` when the request is authenticated via the web session guard. That marks first-party browser session auth. A personal access token is a distinct credential. `EnsureAdminOrigin` requires `TransientToken` before applying Admin-origin binding, so omitting `Origin` cannot bypass the Admin Control session boundary.

Browsers always send `Origin` on cross-origin `fetch`/XHR, and the API host is cross-origin to both SPAs, so script cannot omit it for cookie requests. `Origin: null`, malformed values, other schemes, look-alike hosts and non-Admin origins are rejected for session-authenticated Admin requests. In production an empty Admin origin list rejects every browser Admin request (fail closed). Local/testing with no list configured is not enforced for sessions, as before.

### 7.3 Separate controls

Each control is evaluated independently; none implies another.

- **Authentication** (`auth:sanctum`): session cookie or PAT. Proves identity only.
- **Account access** (`account.access`): suspended/banned/restricted rules, unchanged.
- **Email verification** (`email.verified`): unchanged; admins exempt.
- **Role** (`role:ADMIN`): a trusted origin never makes a user an admin. Business and Ambassador accounts get `403` on Admin routes from every origin and with every credential.
- **Admin session credential** (`admin.origin` / MH-SEC-012): Admin routes require a browser session (`TransientToken`). A PAT never authorizes Admin routes.
- **Origin** (`admin.origin`): for session-authenticated Admin requests, proves the browser claimed the Admin Control origin. A valid Admin identity never proves that by itself.
- **Permission** (`permission:*`): staff role permissions, unchanged.
- **CSRF** (Sanctum stateful middleware): state-changing cookie requests need a valid `X-XSRF-TOKEN`, otherwise `419`. CSRF stops other *sites* from forging requests. It does **not** stop script on `app.*`, which can read the shared `XSRF-TOKEN` cookie; that is what the origin check is for.
- **CORS** (`CORS_ALLOWED_ORIGINS`): only controls which browser origins may *read* responses. It is not authorization and does not stop a request from reaching the server.

### 7.4 Credential handling

- SPA login/register responses contain only `user`. The session cookie is `HttpOnly`.
- Non-browser **Business / Ambassador** login/register responses may include a hashed-stored PAT once. **Admin** login/register responses never include a PAT (MH-SEC-012).
- Logout revokes every PAT and invalidates the current session. Login/register revokes every earlier PAT, including after a browser or Admin login.
- No application code logs `Authorization` headers, PATs or session ids. Error responses use fixed messages (no token, header or exception text when `APP_DEBUG=false`); covered by `BrowserSessionBoundaryTest`.
- MH-SEC-012 does **not** bulk-delete historical Admin PAT rows from the database. Rejection is enforced at the Admin route middleware. Rows are cleared on the user's next login/logout/reset/suspend/ban (existing rotation rules) or when they expire (7 days).

### 7.5 Remaining limitations

- For **participant** Bearer clients, a stolen PAT or password used outside a browser (no `Origin`) is not stopped by Admin-origin binding; that binding applies to Admin session requests. Mitigations remain the 7-day PAT expiry, revocation on login/logout/reset/suspend, role/permission checks, and (future) MFA.
- **Admin** PATs are not accepted for Admin API authorization. Admin accounts do not receive a PAT from the supported login/register flow. Historical Admin PAT rows, if any remain stored, still must not authorize `/api/v1/admin/*`; they are not treated as an accepted residual Admin access path.
- The participant and Admin SPAs still share one session cookie (`SESSION_DOMAIN`). Separate cookies or a separate Admin API host (Options B/C) remain possible later hardening.
- The SPA TypeScript types still declare `token`/`token_type` on login/register results. They are never read at runtime; the types should be made optional in a frontend change.

---

## 8. Related documents

- [API.md](API.md) — auth endpoint contracts
- `frontend/docs/authentication.md` — SPA 401/403 and password UX
- MH-BE-045 Completion Report — SEC-001 / SEC-002 origin
- Foundational TAD §32 / ADR-007 — Sanctum/session; no JWT
