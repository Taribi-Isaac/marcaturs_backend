# Development / UAT seed scenario

Deterministic demo dataset for local Admin UI and upcoming frontend E2E work.

## Safety

- Allowed automatically: `APP_ENV=local` and `APP_ENV=testing`
- Staging only with `php artisan marcaturs:seed-demo --force` **and** `ALLOW_DEMO_SEED=true`
- **Blocked in production** (and any other env)

The seeder never runs from `DatabaseSeeder` automatically.

## How to run

```bash
# Prefer after migrate on a local database
php artisan migrate
php artisan marcaturs:seed-demo

# Or explicitly
php artisan db:seed --class=DevelopmentScenarioSeeder
```

Reseed is safe: demo records (`*@demo.marcaturshub.test`, `demo-*` categories, `Demo *` packages/requirements) are purged then recreated.

Clean slate:

```bash
php artisan migrate:fresh
php artisan marcaturs:seed-demo
```

## Login credentials (development only)

Password for **all** demo users:

```text
DemoPass123!
```

| Role | Email |
| --- | --- |
| Admin (primary) | `admin.primary@demo.marcaturshub.test` |
| Admin (ops) | `admin.ops@demo.marcaturshub.test` |
| Business (active, verified) | `business.solar@demo.marcaturshub.test` |
| Business (active) | `business.tech@demo.marcaturshub.test` |
| Business (active) | `business.logistics@demo.marcaturshub.test` |
| Business (restricted) | `business.edu@demo.marcaturshub.test` |
| Business (suspended) | `business.hospitality@demo.marcaturshub.test` |
| Ambassador (active, verified) | `ambassador.ada@demo.marcaturshub.test` |
| Ambassador (active) | `ambassador.chidi@demo.marcaturshub.test` |
| Ambassador (active) | `ambassador.funke@demo.marcaturshub.test` |
| Ambassador (restricted) | `ambassador.restricted@demo.marcaturshub.test` |
| Ambassador (banned) | `ambassador.banned@demo.marcaturshub.test` |

## Included scenarios

- Categories: allowed, restricted, prohibited/inactive
- Campaign lifecycle: draft, submitted, approved, active, expiring, expired, deactivated, suspended, closed
- Featured packages/purchases (incl. stacked Featured on solar active)
- Extension purchase history (paid, on expired logistics campaign)
- Deals: payment_pending, sealed, completed, cancelled (+ overdue sealed)
- Payment evidence: submitted reference + rejected receipt metadata
- Commissions: due, overdue (due_at in past), paid, received
- Disputes: submitted → closed across statuses with events
- Chat: two Business↔Ambassador conversations with messages
- Notifications: read/unread across commission, Featured, cancel, dispute types
- Verification: not started / pending / under review / approved / rejected / more info
- Verification requirements: Demo Business (legal name + registration evidence) and Demo Ambassador (legal name + identity evidence) are **active** after seed so normal UAT shows a configured checklist

### Verification UAT notes

- **Configured checklist:** login as `ambassador.ada@demo.marcaturshub.test` (or any active Ambassador) → Verification shows Demo requirements.
- **Empty checklist:** Admin with `verification.configure` deactivates all active Ambassador (or Business) requirements → participant status remains `NOT_STARTED` with empty requirements and clear “not configured” copy (not an error). Reactivate Demo requirements before other UAT.
- **Restricted:** `ambassador.restricted@demo.marcaturshub.test` / `business.edu@demo.marcaturshub.test` cannot call verification APIs (`403`).
- **Email verification (MH-GATE-006):** new registrations must verify email before authenticated product access. Seeded demo users are created with `email_verified_at` set. Existing unverified rows are gated until they verify (no fabricated timestamps).

## Limitations

- Marketing/dispute/evidence **files** are metadata paths only (no real binary fixtures streamed).
- Platform payments are **Paid** development rows (not live Paystack charges).
- Dispute categories come from `DisputeCategorySeeder` (also invoked by this scenario).
- Does not create customer accounts, refunds, wallets, escrow, or certification data.
- Does not create phone OTP / SMS verification challenges (`phone` requirements remain text submissions).
- Reference clock for relative dates: `2026-09-07 12:00:00` during seed (then restored).

## Related

- Guard: `App\Support\Development\DevelopmentSeedGuard`
- Seeder: `Database\Seeders\DevelopmentScenarioSeeder`
- Command: `php artisan marcaturs:seed-demo`
