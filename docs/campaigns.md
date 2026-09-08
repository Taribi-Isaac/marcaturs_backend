# Campaign foundation

A campaign is a business's published commercial **opportunity**. Deals bind to a published Campaign Version (MH-BE-015). This module still does not process customer payment or commission.

## Campaign shell (MH-BE-005)

- `campaigns` row owned by a BUSINESS `user_id`
- required `category_id`
- business list/show/create/update of **own drafts** (`title`, `category_id`)

## Campaign versions (MH-BE-006)

Commercial terms live **on the version**. Publishing a version freezes a snapshot. **Published Version ≠ Active Campaign.**

| Version status | Meaning |
| --- | --- |
| `draft` | Mutable. At most one draft per campaign. |
| `published` | Immutable snapshot. Does **not** submit, approve, or activate the campaign. |

`current_campaign_version_id` is the latest published version. Future Deals must bind to a specific published version id.

Admin `GET /api/v1/admin/campaigns/{id}` expands `current_version` with the published commercial snapshot (product, pricing, commission, claims/policies, payment destination name/provider, terms). It omits `payment_account_identifier`, `payment_instructions`, and `payment_contact` (same marketplace redaction). Unpublished/draft versions are never returned as the commercial contract; if a non-published row is incorrectly pointed as current, Admin detail exposes identity only.

Version mutation is blocked while the campaign is `submitted`, `approved`, `suspended`, or `closed`.

## Campaign lifecycle (MH-BE-007)

```text
draft → submitted → approved → active → expiring → expired
                                              ↘ deactivated (business, from active/expiring)
active/expiring → suspended (admin)
submitted/approved/active/expiring/suspended → closed (admin)
submitted → draft (admin reject or request modification)
```

| Current | Action | Next | Actor | Preconditions |
| --- | --- | --- | --- | --- |
| draft | `POST .../submit` | submitted | BUSINESS owner | Published current version; assignable category |
| submitted | `POST .../admin/.../approve` | approved | ADMIN | Same published-version checks |
| submitted | `POST .../reject` | draft | ADMIN | `reason` required |
| submitted | `POST .../request-modification` | draft | ADMIN | `reason` required |
| approved | `POST .../activate` | active | ADMIN | Published current version; listing window set |
| active or expiring | `POST .../deactivate` | deactivated | BUSINESS owner | Record kept |
| active or expiring | `POST .../suspend` | suspended | ADMIN | `reason` required |
| submitted, approved, active, expiring, suspended | `POST .../close` | closed | ADMIN | Record kept |
| active (due) | `campaigns:process-lifecycle` | expired or expiring | system | See expiry |

Activation sets `listing_starts_at` and `listing_expires_at` from `CAMPAIGN_FREE_LISTING_DAYS` (default 30, SoT example / admin-configurable). Expiry does **not** delete the row.

`EXPIRING` is used only when `CAMPAIGN_EXPIRING_LEAD_DAYS` > 0. The documents do not define a lead window, so the default is `0` (ACTIVE → EXPIRED at `listing_expires_at`). Resume-from-suspend is not specified and is not implemented.

There is no `PATCH` of `status`.

## Campaign paid extension (MH-BE-008 / ENG-017)

**Published Version ≠ Active Campaign ≠ Paid Extension.**

Businesses purchase additional active time using administrator-configured packages (SoT example: 30 / 60 / 90 days). Exact Naira prices are configuration, not product constants.

Money boundary: this is **Business → MarcatursHub** via Paystack. It is not customer purchase money and not ambassador commission money.

| Campaign status | Extension allowed | Resulting status after confirmed payment |
| --- | --- | --- |
| `active` | Yes | `active` (days added to current `listing_expires_at`) |
| `expiring` | Yes | `active` (days added to current `listing_expires_at`) |
| `expired` | Yes (EDP: recoverable through extension) | `active` (new window from confirmation time) |
| `draft`, `submitted`, `approved` | No | unchanged |
| `deactivated` | No (not specified as recoverable by payment) | unchanged |
| `suspended` | No (no resume-by-payment rule) | unchanged |
| `closed` | No | unchanged |

Applying an extension still requires a **published** current Campaign Version. The version snapshot is not rewritten.

Payment is initialized server-side. The client cannot set amount or status. Confirmation is Paystack webhook and/or `POST .../extensions/verify`, both of which re-verify the transaction. Duplicate webhooks do not add time twice.

## Featured / Premium visibility (MH-BE-032)

**Extension ≠ Featured.** Extension buys listing time; Featured buys time-bound marketplace visibility for one Campaign (Business → MarcatursHub via Paystack).

| Campaign status | Featured purchase | Marketplace Featured display |
| --- | --- | --- |
| `active` / `expiring` | Yes (published version + assignable category) | Yes while entitlement `expires_at` is future |
| `expired`, `draft`, `submitted`, `approved`, `deactivated`, `suspended`, `closed` | No | No (undiscoverable campaigns never appear) |

Stacking: a new successful purchase sets `expires_at = max(current_active_expires_at, now) + duration_days` (extends remaining Featured time). No hard inventory cap. No refund/credit for unused Featured time (MH-BE-028D). Featured never mutates listing dates, Campaign Versions, Deals, or Commissions.

`campaigns.is_featured` is a synchronized current-state flag (cleared by `campaigns:process-lifecycle` when entitlements expire). Authoritative history lives in `campaign_featured_purchases`.

Marketplace order: Featured first, then `listing_starts_at`, then `id`. Optional filter `featured=true`. Public cards expose `is_featured` only.

## Marketplace discovery (MH-BE-009 / ENG-018)

Public ambassador marketplace. **No Sanctum required.** Hidden campaigns return `404` (same as unknown ids).

Discoverable only when:

- campaign status is `active` or `expiring`;
- `current_campaign_version_id` points at a **published** version;
- category is active and not `prohibited`.

`draft`, `submitted`, `approved`, `deactivated`, `suspended`, `expired`, and `closed` are not listed. Restricted categories remain visible (extra verification rules are still deferred). Inactive/prohibited categories are excluded.

| Query | Meaning |
| --- | --- |
| `q` | Keyword over title, product name/description, service area, category name, business legal/trading name, operating location, commission type/trigger, and `active`/`expiring` status. Does not search payment identifiers, instructions, admin notes, or unpublished versions. |
| `category_id` | Category filter |
| `commission_type` | `percentage` or `fixed` on the published version |
| `service_area` | Location/service-area contains |
| `status` | `active` or `expiring` only |
| `verified` | Business overall verification is `VERIFIED` |
| `featured` | Optional boolean filter for currently Featured campaigns |
| `price_min` / `price_max` | Published version `price_amount` |
| `page` / `per_page` | Pagination (default 15, max 100) |

Order is Featured campaigns first (`is_featured`), then newest listing (`listing_starts_at`, then `id`). Optional `featured=true|false` filter. Save/favourite and the official payment-information page (account numbers) are later tasks. Detail exposes `payment_destination_name` and `payment_provider` only. Marketing **file metadata** appears on detail; file bytes are downloaded through authenticated ambassador/owner/admin endpoints.

## Campaign marketing resources (MH-BE-010 / ENG-019)

Files belong to the **Campaign** (TAD Campaign Media), not the immutable Campaign Version. Version rows still hold `approved_copy` and `marketing_links`.

Supported types: `image`, `flyer`, `video`, `brochure`, `document`.

| Type | Allowed extensions |
| --- | --- |
| `image` | jpeg, jpg, png, webp |
| `flyer` | jpeg, jpg, png, webp, pdf |
| `video` | mp4, webm |
| `brochure`, `document` | pdf |

Max size: `CAMPAIGN_RESOURCE_MAX_FILE_KB` (default 20480). Private disk `campaign_media` (`storage/app/private/campaign-media`); production may point `CAMPAIGN_MEDIA_DISK` at private S3. No public object URLs. Storage keys are `{uuid}.ext`, not the original filename.

There is no admin approval workflow for individual files (campaign approval is separate). Owner upload is available immediately. Owner may replace or permanently delete a file. Records are **not** deleted when a campaign expires or is deactivated.

| Actor | Metadata | File download |
| --- | --- | --- |
| BUSINESS owner | Always, own campaign | Own campaign |
| AMBASSADOR | Public marketplace detail when campaign is discoverable | Authenticated download only if campaign is discoverable (`active` / `expiring`) |
| Guest | Same metadata on public detail | `401` |
| ADMIN | Any campaign | Any campaign |

After a paid extension returns a campaign to `active`, existing files become ambassador-downloadable again without re-upload.

## Not implemented

- Resume from `suspended`
- Promotional/discount engine for packages (admin can change package prices)
- Participant verification as a submit gate
- Dedicated Featured carousel, hard inventory slots, or ML ranking
- Featured expiry reminder notifications
- Refund/credit for unused Featured time (out of MVP per MH-BE-028D)
