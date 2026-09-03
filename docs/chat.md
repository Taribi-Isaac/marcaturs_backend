# Business–Ambassador chat (MH-BE-011E / MH-BE-013)

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

Unchanged: participant IDOR (`404`), text messages, pagination, `POST .../read`, report once, ADMIN reported-only (REST).

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

## Unresolved (not this slice)

Create Deal from chat; Deal↔Conversation FKs; campaign UI filters; dispute vs chat report; retention; attachments; typing/presence; read-receipt broadcasts; admin live monitoring; frontend Echo; production WebSocket infrastructure.
