# Admin Overview (MH-BE-043)

Read-only operational intelligence for ADMIN. Not a BI warehouse, not participant analytics, and not a second Attention engine.

## Product decision gate

### Purpose

Give ADMIN a single authoritative snapshot of platform-wide operational facts derived from existing transactional domains, prioritizing:

1. What needs attention now (aligned with Admin Attention desks)
2. Marketplace volume by lifecycle status
3. Business → MarcatursHub successful platform payment volume

### Included metrics (Tier 1 / Tier 2)

| Metric | Definition | Source | Calculation |
| --- | --- | --- | --- |
| `users.business_registered` | BUSINESS accounts | `users` | `COUNT` where `role=BUSINESS` |
| `users.ambassador_registered` | AMBASSADOR accounts | `users` | `COUNT` where `role=AMBASSADOR` |
| `users.business_active` / `ambassador_active` | Participants with `status=active` | `users` | Conditional `COUNT` |
| `users.restricted` / `suspended` / `banned` | Participant sanctions | `users` | `COUNT` by status (ADMIN excluded) |
| `users.business_verified` / `ambassador_verified` | Overall verification `VERIFIED` | Verification domain | `VerificationStatusCalculator::overallMany` |
| `campaigns.by_status.*` | Campaign lifecycle counts | `campaigns.status` | `GROUP BY status` |
| `campaigns.featured_flagged` | Campaigns with `is_featured=true` | `campaigns` | `COUNT` |
| `campaigns.awaiting_admin_review` | Submitted campaigns | `campaigns` | `status=submitted` |
| `deals.*` | Deal status counts + `payment_confirmed` | `deals` | `GROUP BY status`; confirmed = sealed+completed |
| `commissions.due/paid/received` | Commission statuses | `commissions` | `GROUP BY status` |
| `commissions.overdue` | Due past deadline | `commissions` | `status=due AND due_at < now` |
| `disputes.open` / `by_status` | Dispute case counts | `disputes` | `DisputeStatus::openValues()` / `GROUP BY` |
| `verification.submissions_*` | Submission queue counts | `verification_submissions` | `GROUP BY status` |
| `platform_payments.*.by_currency` | Successful platform payment volume | `platform_payments` | `status=paid`, `SUM(amount_minor)`, group currency+purpose |
| `attention.*` | Operational exception aggregates | Existing domains | Same definitions as Admin Attention desks |

### Platform payment semantics

**Successful platform payment volume** = rows in `platform_payments` with `status=paid`.

Counted purposes only:

- `campaign_extension`
- `campaign_featured`

Excluded: `pending`, `failed`, `cancelled`, unverified, abandoned.

This is **Business → MarcatursHub** money. It is **not**:

- Customer → Business payments
- Business → Ambassador commissions
- accounting revenue recognition, tax, refunds, or chargebacks

Terminology intentionally avoids claiming a legal/accounting definition of “revenue”.

Windows (`today`, `this_month`, `all_time`) use `paid_at` interpreted against `config('app.timezone')` (server clock).

### Deferred (Tier 3 / unsupported)

- Conversion funnels, ROI, retention, cohorts, CLV, attribution
- Geographic / ambassador performance scores
- Certified ambassador counts (certification deferred)
- Suspicious payment-change detection (not implemented)
- Charts / time-series warehouse / exports
- New users trends beyond current counts
- Currency conversion

## API

```http
GET /api/v1/admin/overview
```

Auth: Sanctum + `account.access` + `role:ADMIN`.

Throttle: default `throttle:api`.

Read-only. No query parameters. No side effects.

Sensitive fields (payment account identifiers, tokens, evidence contents, chat bodies, Paystack secrets, storage paths) are never returned.

## Query strategy

Server-side `COUNT` / conditional aggregation / `GROUP BY` / `SUM(amount_minor)` over indexed status columns. Verification verified counts reuse `VerificationStatusCalculator` (authoritative semantics). No N+1 list hydration for dashboard construction. No Redis cache in this slice.

Index added: `platform_payments (status, paid_at)` for paid-window aggregates.
