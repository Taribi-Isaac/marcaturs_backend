# Admin Staff & Fine-Grained RBAC (MH-BE-045 / MH-FE-020)

Approved by **MH-DECISION-001**.

## Model

```text
User.role = ADMIN
  └── AdminStaffProfile.staff_role
        ├── SUPER_ADMIN
        ├── OPERATIONS
        ├── VERIFICATION
        └── MODERATION
```

Permissions are **role-derived and code-defined** (`App\Support\Admin\AdminPermissionMatrix`). There are no per-user ACL overrides and no database permission tables.

## Authorization stack

```text
auth:sanctum → account.access → role:ADMIN → permission:<key>
```

Domain services also call `AdminAuthorization::assert($user, AdminPermission::…)`.

`GET /api/v1/auth/me` for ADMIN includes `staff_role` and `permissions[]`. Business/Ambassador responses are unchanged (no staff fields).

## Staff lifecycle

1. SUPER_ADMIN invites staff (`POST /api/v1/admin/staff/invitations`)
2. Invitee accepts (`POST /api/v1/auth/staff-invitations/accept`) with password
3. User becomes `role=ADMIN` with the invited `staff_role`
4. Disable → `users.status = suspended` (+ token/session revoke)
5. Restore → `users.status = active` (suspended staff only)

Break-glass: `php artisan marcaturs:create-admin {email} --staff-role=SUPER_ADMIN`

Non-production only: `POST /api/v1/admin/staff/direct` for local UAT without email.

## Last Super Admin

Service-layer transactional rule: cannot demote/disable the last **effective** Super Admin
(`role=ADMIN` ∧ `staff_role=SUPER_ADMIN` ∧ `status=active`). Self role-change and self-disable are also forbidden.

## Invitation security

- Token stored as SHA-256 hash only
- Single-use, expiring (default 72h), revocable
- Plain token returned as `debug_token` **only** when `APP_ENV != production`
- Never logged to in-app notifications

## Audit

- `admin_staff_events` for invite/revoke/accept/role/disable/restore
- `campaign_admin_events` for Admin campaign lifecycle actions
- `certification_admin_events` for certification programme/version lifecycle (MH-BE-CERT-01)
- Existing verification/dispute/user status actor fields unchanged

## Certification permissions (MH-BE-CERT-01)

| Permission | SUPER_ADMIN | OPERATIONS | VERIFICATION | MODERATION |
|------------|:-----------:|:----------:|:------------:|:----------:|
| `certification.view` | ✓ | ✓ | — | — |
| `certification.manage` | ✓ | ✓ | — | — |
| `certification.learners.view` | ✓ | ✓ | — | — |

See [certification-programmes.md](certification-programmes.md).

## Admin UI

Admin Control:

- Staff list/detail, invite, role change, disable/restore
- Nav and routes filtered by `/auth/me` permissions
- Public accept page: `/staff/accept-invitation?token=…`

## UAT (local)

1. Sign in as Super Admin (demo: `admin.primary@demo.marcaturshub.test`)
2. Invite or direct-create Operations / Verification staff
3. Sign in from another browser/profile and confirm least-privilege nav
