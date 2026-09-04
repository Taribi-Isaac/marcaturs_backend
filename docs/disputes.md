# Disputes (MH-BE-025D)

A Dispute is an **operational investigation case**. It is not a Deal status and does not mutate Commission or Deal financial state.

## Locked product contract

* Deal statuses remain `payment_pending` → `sealed` → `completed` (no `disputed`).
* “Disputed” for UI/filtering means the Deal has an applicable/open Dispute case.
* Deal list/show expose derived fields `has_open_dispute` and `open_dispute_count` (MH-BE-026B), aligned with `DisputeStatus::isOpen()`.
* No settlement freeze: open Disputes do not block mark-paid, confirm-received, completion, overdue detection, or reminders.
* Post-completion Disputes are allowed; the Deal stays `completed`.
* Resolution records decision/action notes only — no clawback, refund, completion reversal, or Commission rewrite.

## Statuses (Phase 1)

```text
submitted → under_review → evidence_requested ⇄ under_review
under_review → decision_pending ⇄ evidence_requested
decision_pending → resolved → closed
```

Not implemented: `appealed`, `action_taken`, dispute-outcome appeal.

## Categories

Admin-configurable `dispute_categories` (not a PHP enum). Seeded examples: unpaid commission, commission amount disputed, campaign terms changed, commission withheld, unauthorized claims, fraudulent evidence, misrepresentation, customer complaint, campaign abuse, other.

## Endpoints

| Method | Path | Role |
| --- | --- | --- |
| `GET` | `/api/v1/dispute-categories` | BUSINESS, AMBASSADOR (active) |
| `POST` | `/api/v1/deals/{deal}/disputes` | Deal party BUSINESS/AMBASSADOR |
| `GET` | `/api/v1/disputes` | Party (reporter or accused) |
| `GET` | `/api/v1/disputes/{id}` | Party |
| `POST` | `/api/v1/disputes/{id}/attachments` | Party (status gate) |
| `GET` | `/api/v1/disputes/{id}/attachments/{id}/download` | Party |
| `GET/POST/PATCH` | `/api/v1/admin/dispute-categories` | ADMIN |
| `GET` | `/api/v1/admin/disputes` | ADMIN |
| `GET` | `/api/v1/admin/disputes/{id}` | ADMIN (+ related Deal/Commission/evidence) |
| `POST` | `/api/v1/admin/disputes/{id}/start-review` | ADMIN |
| `POST` | `/api/v1/admin/disputes/{id}/request-evidence` | ADMIN (`reason` required) |
| `POST` | `/api/v1/admin/disputes/{id}/resume-review` | ADMIN |
| `POST` | `/api/v1/admin/disputes/{id}/mark-decision-pending` | ADMIN |
| `POST` | `/api/v1/admin/disputes/{id}/resolve` | ADMIN (`decision_notes` + `action_notes`) |
| `POST` | `/api/v1/admin/disputes/{id}/close` | ADMIN |
| `POST/GET` | `/api/v1/admin/disputes/{id}/attachments...` | ADMIN |

No generic `PATCH status`. Create body: `{ "category_id", "description" }`. Public case reference looks like `MH-D-…`.

## Attachments

Separate `dispute_attachments` on private disk (`sensitive`). Types: pdf, jpeg, jpg, png, webp. Max 5120 KB (`DISPUTE_ATTACHMENT_MAX_KB`). No public URLs. Party upload while `submitted` / `under_review` / `evidence_requested`. No party upload after `resolved`/`closed`. Retention deferred.

## Notifications

In-app + email via MH-BE-022C:

* `dispute_opened` → accused
* `dispute_resolved` → reporter and accused

Queued after commit. No SMS/WhatsApp.

## Audit events

`dispute_created`, `dispute_review_started`, `dispute_evidence_requested`, `dispute_review_resumed`, `dispute_decision_pending`, `dispute_resolved`, `dispute_closed`. No `deal_disputed`.
