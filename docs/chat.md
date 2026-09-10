# Business–Ambassador chat (MH-BE-011 / ENG-020; refined MH-BE-011E; realtime MH-BE-013)

Conversations are **Business↔Ambassador communication relationships**. They are **not** Campaign-scoped and do **not** require a Campaign or Deal.

```text
Business ──── Conversation ──── Ambassador
```

**One primary conversation** exists per Business user + Ambassador user (`UNIQUE(business_user_id, ambassador_user_id)`).

Campaign, Campaign Version, Deal, and Deal Event are independent domains. Chat is not a CRM.

## Opening

`POST /api/v1/conversations`

| Actor | Body |
| --- | --- |
| BUSINESS | `{ "ambassador_id": 123 }` |
| AMBASSADOR | `{ "business_id": 789 }` |

`campaign_id` is **prohibited** (`400`). **201** if created, **200** if the pair already exists. Concurrent opens cannot create a second row.

## Participants, messages, report, admin

Participant IDOR → `404`. Text messages only (`type=text`, max chars via `CHAT_MESSAGE_MAX_CHARS`). Paginated history (`page` / `per_page`). `POST .../read` sets `read_at` on inbound messages. Report once (`POST .../report`). ADMIN may list/show reported conversations and their messages (REST only; access logged).

Account access: same Sanctum + `account.access` as the rest of the API (restricted / suspended / banned cannot use chat beyond the platform’s existing restricted allow-list). Guests and wrong roles are rejected.

## Realtime (MH-BE-013)

MySQL remains the source of truth. `POST /api/v1/conversations/{id}/messages` persists the message, returns **201**, then attempts to broadcast `message.created` on the **private** channel `conversation.{id}`.

Channel authorization is **not** inherited from the REST chat routes. Clients authorize with Sanctum on:

`POST /api/broadcasting/auth`

Body (Pusher protocol): `socket_id`, `channel_name` = `private-conversation.{id}`.

Rules: authenticated BUSINESS or AMBASSADOR participant; `account.access` (restricted / suspended / banned denied). Guesses of unknown IDs fail. ADMIN users cannot subscribe on this slice (reported conversations stay REST).

If Reverb or broadcasting is down, the REST **201** still succeeds. Recipients recover via `GET /api/v1/conversations/{id}/messages`. Broadcast retries must not insert another `messages` row.

Local process (in addition to `php artisan serve` and Redis):

```bash
php artisan reverb:start
```

Set `BROADCAST_CONNECTION=reverb` and the `REVERB_*` placeholders in `.env` (see `.env.example`). Chat broadcasting uses `ShouldBroadcastNow` after the DB transaction commits; it does **not** require a queue worker.

Production WebSocket routing (ALB / CloudFront / process supervision) is **not** decided. Do not treat local Reverb as production topology.

## Unresolved / deferred (not invented in this foundation)

| Topic | Status |
| --- | --- |
| Create Deal / actionable commercial event cards from chat | Deferred (EDP Phase 4 §21; requires deliberate product design atop Deals) |
| Deal↔Conversation / Campaign↔Conversation FKs | Not required for foundation; uniqueness is Business↔Ambassador pair |
| Attachments | PRD “where appropriate” — not implemented (no chat attachment rules) |
| Message edit / delete | Unspecified — not implemented |
| Conversation lifecycle (close/archive/block) | Unspecified — not implemented beyond report |
| Block participant semantics | Named in PRD only — not implemented |
| New-message in-app/email notifications | Unspecified in UX notification lists — not implemented |
| Typing / presence / read-receipt broadcasts | Optional in TAD — not implemented |
| Admin live WebSocket monitoring | Not implemented |
| Retention policy | Named class only — TBD |
| Production WebSocket infrastructure | Pending |
