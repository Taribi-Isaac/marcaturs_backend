# Platform payments

MarcatursHub uses Paystack only for **platform products**. The first product is campaign listing extension.

```text
Customer → Business          (not Paystack, not this module)
Business → Ambassador        (not Paystack, not this module)
Business → MarcatursHub      (Paystack: campaign extension)
```

There are no customer wallets, ambassador wallets, or escrow tables.

## Confirmation

Never trust a client `payment=successful` flag.

1. Server initializes Paystack with the package amount stored on the payment row.
2. Business is redirected to `authorization_url`.
3. Paystack `charge.success` webhook is signature-checked (`X-Paystack-Signature`, HMAC SHA512 of the raw body).
4. The backend verifies the transaction with Paystack.
5. Amount and currency must match the initialized payment.
6. The campaign extension is applied inside a database transaction keyed by `platform_payments.reference` / unique `campaign_extensions.platform_payment_id`.

`POST /api/v1/campaigns/{id}/extensions/verify` is the same confirmation path for the return URL. Repeating it is idempotent.

## Configuration

| Variable | Purpose |
| --- | --- |
| `PAYSTACK_SECRET_KEY` | Server secret (never exposed to API clients) |
| `PAYSTACK_PUBLIC_KEY` | Optional frontend key; not required by this API |
| `PAYSTACK_BASE_URL` | Default `https://api.paystack.co` |
| `PAYSTACK_CALLBACK_URL` | Optional Paystack return URL |

Webhook: `POST /api/v1/webhooks/paystack` (no Sanctum).
