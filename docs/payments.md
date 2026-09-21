# Platform payments

MarcatursHub uses Paystack only for **platform products**: campaign listing extension, Featured / Premium visibility, and **certification enrollment**.

```text
Customer → Business          (not Paystack, not this module)
Business → Ambassador        (not Paystack, not this module)
Business/Ambassador → MarcatursHub  (Paystack: extension, Featured, certification)
```

There are no customer wallets, ambassador wallets, or escrow tables.

## Confirmation

Never trust a client `payment=successful` flag.

1. Server initializes Paystack with the amount stored on the payment row.
2. Payer is redirected to `authorization_url`.
3. Paystack `charge.success` webhook is signature-checked (`X-Paystack-Signature`, HMAC SHA512 of the raw body).
4. The backend verifies the transaction with Paystack.
5. Amount and currency must match the initialized payment.
6. Confirmation paths bind to the persisted `platform_payments.purpose`. A reference for Featured or Certification cannot activate a Campaign Extension (and the reverse), even when ownership/campaign IDs otherwise match.
7. The product effect is applied inside a database transaction keyed by `platform_payments.reference` and a unique product row on `platform_payment_id`.
8. Webhook routing uses `platform_payments.purpose` (`campaign_extension`, `campaign_featured`, or `certification_enrollment`).

Verify endpoints share the same confirmation trust model. Repeating them is idempotent.

## Certification enrollment

- Initialize: `POST /api/v1/certification/programmes/{id}/purchase/initialize`
- Verify: `POST /api/v1/certification/purchases/verify`
- Snapshots `certification_programme_id` + `certification_programme_version_id` + fee on the payment row at initialize.
- Successful confirmation creates `certification_enrollments` (`status=active`) bound to that version.
- If the Ambassador is already enrolled and a later provider-confirmed reference is verified (retry, race, or second pending payment), the payment is marked `paid`, the existing enrollment is returned, and no duplicate enrollment or activation notification is created.

## Configuration

| Variable | Purpose |
| --- | --- |
| `PAYSTACK_SECRET_KEY` | Server secret (never exposed to API clients) |
| `PAYSTACK_PUBLIC_KEY` | Optional frontend key; not required by this API |
| `PAYSTACK_BASE_URL` | Default `https://api.paystack.co` |
| `FRONTEND_URL` | Preferred browser origin for Paystack **return** URLs (e.g. local Vite `http://localhost:5180`, staging/production SPA origin). Used by `PaystackReturnUrl` for certification and campaign checkout returns. |
| `PAYSTACK_CALLBACK_URL` | Optional full-URL override when `FRONTEND_URL` is unset (legacy). Not the webhook path. |

Return URLs are environment-driven. Do not hard-code `localhost` / `127.0.0.1` into payment services. Local development must set `FRONTEND_URL` to the SPA origin you actually open in the browser (participant Vite defaults to port **5180**).

When the secret is missing, initialize endpoints return **503** `service_unavailable` with the participant-safe message `Payments are temporarily unavailable. Please try again later.` Configuration detail is logged server-side only and must not appear in API or UI copy.

Webhook: `POST /api/v1/webhooks/paystack` (no Sanctum).
