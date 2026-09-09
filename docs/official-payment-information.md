# Official Payment Information (MH-BE-042)

## Purpose

Give Ambassadors a safe, shareable public reference so a **customer without a MarcatursHub account** can see the Business’s official payment destination for a specific discoverable campaign’s published commercial terms.

## Financial boundary

- **Customer → Business** for the product purchase
- **Business → Ambassador** for commission (separate flow)
- **MarcatursHub does not** receive, hold, route, escrow, reconcile, or process the customer purchase payment
- Paystack remains for **platform fees** only (campaign extension / Featured)

## Endpoint

```http
GET /api/v1/public/official-payment-information/{token}
```

- Public / read-only
- No Sanctum required
- Rate limited: `official-payment` (default 30 requests/minute per IP)
- Canonical envelope: `{ "success": true, "data": { ... } }`

## Public identifier

- Column: `campaigns.official_payment_token` (unique, 48 lowercase alphanumeric characters)
- Generated when a Campaign Version is published (if missing) or lazily when share metadata is resolved
- Stable across subsequent version publishes for the same campaign
- Opaque — not a sequential database id
- Invalid / unknown / ineligible tokens all return the same **404** `not_found` shape (no enumeration hint)

Frontend-oriented share path: `/pay/{token}` (absolute URL uses `FRONTEND_URL` when configured).

## Data source

Single source of truth: the campaign’s **published current Campaign Version** (`current_campaign_version_id`).

Businesses continue to manage payment destination fields only through Campaign Version create/update/publish. This feature does **not** introduce a second payment settings table.

## Eligibility

Must satisfy marketplace `Campaign::scopeDiscoverable()`:

1. Status is `active` or `expiring`
2. `current_campaign_version_id` is set
3. That version status is `published`
4. Category is assignable
5. Owning Business user is `active`

Not eligible (404): `draft`, `submitted`, `approved`, `expired`, `deactivated`, `suspended`, `closed`.

Historical URLs stop working when a campaign leaves discoverable states.

## Response fields

| Group | Fields |
| --- | --- |
| Financial boundary | `customer_pays`, `platform_holds_customer_funds`, `statement` |
| Share | `token`, `path`, `share_path`, `share_url` |
| Business | `legal_name`, `trading_name`, `operating_location`, `website`, `verification_status` |
| Campaign | `id`, `title`, `status`, `category` |
| Campaign version | `version_number`, `status`, `published_at`, product/pricing/service area |
| Payment destination | `destination_name`, `provider`, `account_identifier`, `instructions`, `contact` |

## Intentionally excluded

- Draft / unpublished version terms
- Storage keys / paths / signed URLs
- Verification evidence and private documents
- Chat / messages
- Admin review reasons / internal IDs beyond campaign id
- Deal ids, evidence, commissions
- Raw `user` objects / emails
- Marketplace still omits account identifier / instructions / contact on list & detail (only name + provider + share refs on detail)

## Relationship to Deals

- Page view has **no Deal-side effect**
- Does not create Deals
- Does not mutate Deal commercial snapshots
- When a new version is published, the public page follows the new current version; existing Deals keep their bound version snapshot

## Relationship to Payment Evidence

- Official Payment Information = where/how the customer should pay
- Payment Evidence = what the Ambassador later submits as proof
- Page view does **not** create evidence

## Logging

Sensitive logging already redacts keys containing `token`, `payment_account_identifier`, and `payment_instructions`. Prefer not logging full tokens or account numbers in application code.
