# Platform payments

MarcatursHub uses Paystack only for **platform products**: campaign listing extension and Featured / Premium visibility.

```text
Customer → Business          (not Paystack, not this module)
Business → Ambassador        (not Paystack, not this module)
Business → MarcatursHub      (Paystack: campaign extension, Featured)
```

There are no customer wallets, ambassador wallets, or escrow tables.

## Confirmation

Never trust a client `payment=successful` flag.

1. Server initializes Paystack with the package amount stored on the payment row.
2. Business is redirected to `authorization_url`.
3. Paystack `charge.success` webhook is signature-checked (`X-Paystack-Signature`, HMAC SHA512 of the raw body).
4. The backend verifies the transaction with Paystack.
5. Amount and currency must match the initialized payment.
6. The product effect is applied inside a database transaction keyed by `platform_payments.reference` and a unique purchase/extension row on `platform_payment_id`.
7. Webhook routing uses `platform_payments.purpose` (`campaign_extension` or `campaign_featured`).

`POST /api/v1/campaigns/{id}/extensions/verify` and `POST /api/v1/campaigns/{id}/featured/verify` share the same confirmation trust model. Repeating them is idempotent.

## Configuration

| Variable | Purpose |
| --- | --- |
| `PAYSTACK_SECRET_KEY` | Server secret (never exposed to API clients) |
| `PAYSTACK_PUBLIC_KEY` | Optional frontend key; not required by this API |
| `PAYSTACK_BASE_URL` | Default `https://api.paystack.co` |
| `PAYSTACK_CALLBACK_URL` | Optional Paystack return URL |

Webhook: `POST /api/v1/webhooks/paystack` (no Sanctum).
