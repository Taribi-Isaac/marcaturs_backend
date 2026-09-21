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
| **Personal Access Token (PAT)** | `createToken('auth')` returned as Bearer on login/register | API tooling, tests, future mobile; also present in login JSON |

First-party SPAs authenticate with **session cookies**, not by storing the Bearer PAT in `localStorage`. PATs must still be purpose-bound and time-bounded because they are issued, can be copied from responses, and may be used with `Authorization: Bearer`.

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
2. Create **exactly one** new PAT named `auth` with the 7-day lifetime.
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
| Participant SPA | Primary auth remains cookie + CSRF. No required storage of Bearer PAT. Idle session still follows `SESSION_LIFETIME`. |
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

- Login creates one PAT and leaves zero prior PATs.
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

## 7. Related documents

- [API.md](API.md) — auth endpoint contracts
- `frontend/docs/authentication.md` — SPA 401/403 and password UX
- MH-BE-045 Completion Report — SEC-001 / SEC-002 origin
- Foundational TAD §32 / ADR-007 — Sanctum/session; no JWT
