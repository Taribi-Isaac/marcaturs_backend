# Deals (MH-BE-015)

A Deal is a **versioned commercial transaction record**. It is not a lead, CRM object, or customer account.

```text
Business ── Campaign ── Campaign Version
                              │
Ambassador ──────────────── ► Deal
                              ├── Deal Events
                              └── Payment Evidence
```

Conversation, Campaign discovery, and PlatformPayment remain independent. Payment evidence (MH-BE-016) does **not** confirm payment, seal the Deal, or create commission. Disputes are a separate case domain ([docs/disputes.md](disputes.md)). Chat Create Deal and Deal WebSockets remain later slices.

## Status lifecycle

```text
payment_pending → sealed → completed
payment_pending → cancelled
```

`completed` is the normal terminal status (MH-BE-023B). It is set automatically when Commission receipt is confirmed. `cancelled` is a terminal exception status for pre-seal abandonment (MH-BE-027C). Sealed/completed Deals cannot be cancelled in MVP. Disputes do not change Deal status (MH-BE-025D).

Deal `status` values: `payment_pending` / `sealed` / `completed` / `cancelled`. An open Dispute does not freeze settlement or completion.

## Creation

`POST /api/v1/deals` — **AMBASSADOR** only.

```json
{ "campaign_id": 123, "expected_transaction_amount": 100000 }
```

`expected_transaction_amount` is optional (SoT “where applicable”). The server ignores client `business_id`, `campaign_version_id`, status, and commission fields.

The server:

1. Loads the Campaign.
2. Allows new Deals only when status is `active` or `expiring`.
3. Binds `current_campaign_version_id` only if that version is **published**.
4. Sets `business_user_id` from `campaigns.user_id`.
5. Snapshots commercial terms from that version.
6. Sets status `payment_pending`.
7. Writes `deal_created` in the same transaction.

| Campaign status | New Deal |
| --- | --- |
| active, expiring | yes |
| draft, submitted, approved, expired, deactivated, suspended, closed | no |

Existing Deals are not cancelled when a Campaign later leaves `active`/`expiring`.

Multiple Deals may share the same Ambassador, Business, Campaign, or Campaign Version. There is no reopen and no PATCH.

## List and show

| Method | Path | Role |
| --- | --- | --- |
| `GET` | `/api/v1/deals` | BUSINESS (own as business), AMBASSADOR (own as ambassador) |
| `GET` | `/api/v1/deals/{id}` | Same party rule; others `404` |

Show includes `deal_created` history. Responses do not include payment account identifiers, customer identity, or conversation IDs.

List and show also expose derived open-dispute visibility (MH-BE-026B), computed from related Dispute rows — not persisted Deal columns:

| Field | Meaning |
| --- | --- |
| `has_open_dispute` | `true` when at least one related Dispute is open per `DisputeStatus::isOpen()` |
| `open_dispute_count` | Count of those open Disputes (multiple open cases per Deal are allowed) |

Deal `status` remains one of `payment_pending` / `sealed` / `completed` / `cancelled`. An open Dispute does not freeze settlement or completion.

## Cancellation (MH-BE-027C)

`POST /api/v1/deals/{deal}/cancel` — Deal **BUSINESS** or Deal **AMBASSADOR** (unilateral).

```json
{ "reason": "Customer withdrew before payment." }
```

`reason` is required (free text). Eligible only while `payment_pending`. Transition: `payment_pending → cancelled` (terminal). Already cancelled returns `200` without a second event or notifications. `sealed` / `completed` return `409`.

| Effect | Behavior |
| --- | --- |
| Commission | None created or mutated |
| Payment evidence | Existing rows retained; new submissions `409` |
| Dispute | Not created/closed by cancel |
| Audit | `deal_cancelled` DealEvent; `cancelled_at` set once |
| Notifications | Both parties, in-app + email |

Refund, clawback, and post-seal cancellation are out of scope.

## Payment evidence (MH-BE-016)

Payment evidence is the Ambassador’s claim that the customer paid the Business **directly**. It is **not** payment confirmation and **does not** create commission liability.

```text
POST /api/v1/deals/{deal}/payment-evidence
```

**AMBASSADOR of that Deal only.** Multipart or JSON. Server sets submitter, Deal, and `submitted` status.

| Field | Required | Source |
| --- | --- | --- |
| `kind` | yes | `receipt`, `transfer_confirmation`, `transaction_screenshot`, `transaction_reference`, `other` (BRD FR-020 / UX §17) |
| `file` | required except `transaction_reference` (and `other` may use a reference instead) | private `sensitive` disk |
| `reference_number` | required for `transaction_reference` | TAD §22 |
| `amount`, `currency`, `paid_on`, `note` | optional | TAD §22 |

Allowed files: pdf, jpeg, jpg, png, webp; default max 5120 KB (`DEAL_PAYMENT_EVIDENCE_MAX_KB`). Paths and disks are never returned. Parties download via the authorized download route.

| Method | Path | Role |
| --- | --- | --- |
| `POST` | `/api/v1/deals/{deal}/payment-evidence` | Deal Ambassador |
| `GET` | `/api/v1/deals/{deal}/payment-evidence` | Deal Ambassador or Deal Business |
| `GET` | `/api/v1/deals/{deal}/payment-evidence/{id}` | Same party rule; others `404` |
| `GET` | `/api/v1/deals/{deal}/payment-evidence/{id}/download` | Same party rule; `404` if no file |

Deal status stays `payment_pending` after evidence. A `payment_evidence_submitted` Deal Event is written in the same transaction. Multiple submissions append; there is no PATCH. Evidence status `rejected` is written by Business rejection (MH-BE-018). No Admin evidence API.

## Payment confirmation (MH-BE-018)

Business confirmation of a **qualifying** customer payment **seals** the Deal. It is not customer Paystack, not a Commission row, and not payout.

`POST /api/v1/deals/{deal}/confirm` — Deal **BUSINESS** only.

Requires:

* Deal `payment_pending` (already `sealed` is idempotent);
* at least one Payment Evidence with status `submitted` (file-less `transaction_reference` counts);
* `commission_trigger = payment_confirmation` (`other` → 422);
* for **percentage** commission: `confirmed_payment_amount` (Business-confirmed customer payment). Server calculates `commission_amount = confirmed_payment_amount × commission_rate / 100` with BCMath. Ambassador expected amount, evidence amount, and campaign price are not the principal.
* for **fixed** commission: snapshot `commission_amount` is kept.

Success: status `sealed`, `confirmed_at` set. In the **same transaction** a Commission row is created (`status=due`, `UNIQUE deal_id`) and events `payment_confirmed`, `deal_sealed`, `commission_due` are written. Metadata includes `commission_id` and submitted evidence IDs. Amount on Commission is copied from the sealed Deal (no second percentage formula). `due_at` = `confirmed_at` + `min(7, deal.commission_payment_deadline_days)` calendar days.

## Commission liability (MH-BE-019) and settlement recording (MH-BE-020)

Commission is a **Business → Ambassador** liability record. MarcatursHub does not hold, route, or pay it. Paystack is not used for this money.

Lifecycle: `due` → `paid` (Business declaration that off-platform payment was sent) → `received` (Ambassador confirms receipt). `paid` is not verified proof of transfer. Optional `payment_reference` / `payment_note` are declarations/metadata, not MH-BE-016 Payment Evidence (Customer → Business).

Immutable after create: Deal, parties, campaign version, type, rate, amount, currency, `became_due_at`, `due_at`. Settlement may set `status`, `paid_at`, `received_at`, and optional reference/note.

Late `due → paid` is allowed (`paid_at` may be after `due_at`). Deal status remains `sealed` until the Ambassador confirms receipt.

## Deal completion (MH-BE-023B)

Deal status values: `payment_pending` → `sealed` → `completed`.

There is **no** separate Complete Deal API. When the Ambassador successfully confirms Commission receipt (`POST /api/v1/commissions/{id}/confirm-received`), the same transaction also transitions the associated Deal:

`sealed → completed`

and writes exactly one Deal Event `deal_completed` (actor = Ambassador; metadata: `commission_id`, `received_at`). Idempotent retries of confirm-received do not duplicate Commission or Deal completion events and do not alter `received_at`.

Only `sealed → completed` is allowed for completion. `payment_pending → completed` is not permitted. `completed` is terminal for the normal lifecycle in this slice (no revert to `sealed` or `payment_pending`). Pre-seal cancellation is `payment_pending → cancelled` (MH-BE-027C). Refund and post-seal cancellation remain deferred.

Overdue Commissions may still be settled late; late settlement still completes the Deal. Overdue is never a Deal status.

Overdue is a **derived condition**, not a persisted status. A Commission is overdue when `status = due AND now > due_at`. Once `paid` or `received`, `is_overdue` is always `false`. The scheduler (`commissions:process-overdue`, every 15 minutes) writes a one-time immutable `commission_overdue` event on `commission_events` when overdue is first detected. The event has a null actor (system), `due_at`, and `detected_at` metadata. It persists after payment so historical lateness is auditable.

Commission reminders (MH-BE-022E) are observational notifications only. The scheduler (`commissions:process-reminders`, every 15 minutes) derives five Business payment-pressure slots from `due_at` (−2, 0, +1, +4, +7 calendar days). Ambassador receives status awareness (`commission_due`, single `commission_overdue`, `commission_paid`) and does not receive the Business pressure cadence. Reminders never mutate Commission or Deal state.

| Method | Path | Role |
| --- | --- | --- |
| `GET` | `/api/v1/commissions` | BUSINESS (as debtor), AMBASSADOR (as payee) |
| `GET` | `/api/v1/commissions/{id}` | Same party rule; others `404` |
| `POST` | `/api/v1/commissions/{id}/mark-paid` | Deal BUSINESS; body optional `payment_reference`, `payment_note` |
| `POST` | `/api/v1/commissions/{id}/confirm-received` | Deal AMBASSADOR; no financial fields |

Ambassador cannot confirm a `due` Commission (`422`). Business cannot confirm received (`403`). Admin cannot settle (`403`). Idempotent retries of the same transition do not duplicate `commission_events` (`UNIQUE(commission_id, type)`). `received → paid` is `409`. Settlement writes `commission_paid` / `commission_received` on `commission_events`. Successful `confirm-received` also writes `deal_completed` on `deal_events` in the same transaction (MH-BE-023B).


`POST /api/v1/deals/{deal}/payment-evidence/{id}/reject` — Deal **BUSINESS** only. Body `{ "reason": "..." }` (required). Evidence becomes `rejected`. Deal stays `payment_pending`. Event `payment_rejected`. Ambassador may submit new evidence. Sealed Deal reject → 409.

Ambassador, Admin, and other Businesses cannot confirm or reject (403 / 404).


File MIME/size follow the MH-BE-004 sensitive-upload convention because UX requires restrictions but does not publish product constants. Automated retention is not implemented.


## Snapshot (from the bound published version)

`product_name`, `pricing_method`, `price_amount`, `price_currency`, `commission_type`, `commission_rate`, `commission_amount`, `commission_trigger`, `commission_trigger_description`, `commission_payment_deadline_days`, `minimum_qualifying_amount`, `qualifying_conditions`.

Later published versions do not rewrite these columns.
