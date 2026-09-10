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

Order is Featured campaigns first (`is_featured`), then newest listing (`listing_starts_at`, then `id`). Optional `featured=true|false` filter. Save/favourite is a later task. Detail exposes `payment_destination_name` and `payment_provider` only (not account identifiers). Detail also exposes `official_payment` share references (`token`, `path`, `share_path`, `share_url`) for Ambassadors to copy the Official Payment Information link. Marketing **file metadata** appears on detail; file bytes are downloaded through authenticated ambassador/owner/admin endpoints.

## Official Payment Information (MH-BE-042)

Public, unauthenticated, read-only page for customers to see where to pay the Business for a discoverable campaign.

| Item | Rule |
| --- | --- |
| Endpoint | `GET /api/v1/public/official-payment-information/{token}` |
| Auth | None required (Business/Ambassador sessions also work) |
| Source of truth | Published **current** Campaign Version payment destination fields |
| Identifier | Opaque `campaigns.official_payment_token` (48-char), generated on version publish / first share resolution |
| Eligibility | Same as marketplace `discoverable()`: `active` \| `expiring` + published current version + assignable category + active Business owner |
| Ineligible | `draft`, `submitted`, `approved`, `expired`, `deactivated`, `suspended`, `closed` → **404** (same shape as unknown token) |
| Rate limit | `throttle:official-payment` (default 30/min by IP) |

**Financial boundary:** Customer → Business. MarcatursHub does not receive, hold, route, escrow, or process this purchase payment. Paystack remains platform-fee only (extension/Featured).

**Exposed payment fields:** `destination_name`, `provider`, `account_identifier`, `instructions`, `contact` (from the published current version).

**Not exposed:** storage keys, verification evidence, chat, admin fields, Deal data, private user IDs beyond campaign id for context. Marketplace list/detail continue to redact account identifier / instructions / contact.

**Deals / evidence:** Viewing this page creates neither a Deal nor Payment Evidence. Deal commercial snapshots are never rewritten when a new version is published; the public page always follows the current published version.

**Share URL:** API returns `share.path` (API) and `share.share_path` / `share.share_url` (frontend route `/pay/{token}` via `FRONTEND_URL`) for “Copy Official Payment Link”.

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

## Campaign Cover Image (MH-BE-044)

Primary **Campaign-level presentation image** (zero or one per Campaign). Separate from:

- Campaign Version commercial terms (cover changes never create/alter versions, Deal snapshots, or commissions)
- Campaign Marketing Resources (multi-file promotional collection)

### Authoritative basis / product decisions

- TAD §18 Campaign Media + ERD `CAMPAIGNS ||--o{ CAMPAIGN_MEDIA` place media on the Campaign, not Version
- UX discovery cards do not list a cover as a required text field; Product direction (this task) adds an optional primary visual for cards/detail/Featured
- Ambiguity: foundational docs do not fully specify a dedicated Cover distinct from marketing resources — Cover is a first-class presentation asset so Marketing Resources semantics stay unchanged

### Data model

Table `campaign_covers`: `campaign_id` **UNIQUE**, `uploaded_by`, `disk`, `path`, `original_filename`, `mime_type`, `size_bytes`, timestamps. FK `campaign_id` / `uploaded_by` `restrictOnDelete`.

### Storage

Reuses private disk `campaign_media` (`CAMPAIGN_MEDIA_DISK`). Keys:

```text
campaigns/{campaignId}/cover/{uuid}.{ext}
```

No public object URLs. API never returns `disk` / `path`.

### Formats & size

Raster only: `jpeg`, `jpg`, `png`, `webp` (no SVG/GIF/TIFF). Max size reuses `CAMPAIGN_RESOURCE_MAX_FILE_KB` (default 20480) — no separate cover limit.

### Lifecycle

Same smallest safe rule as marketing resources: BUSINESS owner may create/replace/delete in **any** Campaign status. Cover mutation does not change Campaign status or Version. Not defined as version-gated in foundational docs.

### Public delivery

Controlled stream (not direct storage):

```http
GET /api/v1/marketplace/campaigns/{id}/cover
```

Public, unauthenticated. Allowed only when the Campaign is marketplace-discoverable **and** a cover exists; otherwise `404`. Marketplace list/detail expose:

```json
"cover_image": { "available": true, "url": "https://…/api/v1/marketplace/campaigns/{id}/cover" }
```

or `available: false`, `url: null`. Featured cards use the same Campaign Cover (no Featured-specific image copy).

### Owner / Admin

| Method | Path | Actor |
| --- | --- | --- |
| `POST` | `/api/v1/campaigns/{id}/cover` | BUSINESS owner (create or replace; `201` / `200`) |
| `GET` | `/api/v1/campaigns/{id}/cover` | BUSINESS owner metadata |
| `GET` | `/api/v1/campaigns/{id}/cover/download` | BUSINESS owner stream |
| `DELETE` | `/api/v1/campaigns/{id}/cover` | BUSINESS owner |
| `GET` | `/api/v1/admin/campaigns/{id}/cover` | ADMIN metadata |
| `GET` | `/api/v1/admin/campaigns/{id}/cover/download` | ADMIN stream |

Replacement: store new file → persist row → delete previous file. Validation failure leaves the existing cover intact.

### Seed

Deterministic seed is **unchanged** (no binary cover fixtures committed).

## Not implemented

- Resume from `suspended`
- Promotional/discount engine for packages (admin can change package prices)
- Participant verification as a submit gate
- Dedicated Featured carousel, hard inventory slots, or ML ranking
- Featured expiry reminder notifications
- Refund/credit for unused Featured time (out of MVP per MH-BE-028D)
- Campaign cover galleries, cropping, AI moderation, CDN transforms, versioned cover snapshots
