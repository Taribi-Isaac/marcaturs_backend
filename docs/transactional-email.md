# Transactional email (MH-GATE-007)

Inventory of **implemented** MarcatursHub transactional mail, local inspection, and queue requirements.

Foundational documents remain authoritative for product intent. This file documents **code reality**.

## Local mail configuration

| Variable | Local default | Purpose |
| --- | --- | --- |
| `MAIL_MAILER` | `log` | Renders messages to a log channel (no external SMTP) |
| `MAIL_LOG_CHANNEL` | `mh_mail` | Writes to `storage/logs/mail.log` |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | example / app name | Envelope from |
| `QUEUE_CONNECTION` | `redis` | All app notifications implement `ShouldQueue` |

**Critical local rule:** with Redis queues, a successful API response only means the notification was **queued**. Rendered mail appears in `storage/logs/mail.log` only after:

```bash
php artisan queue:work
# or one-shot:
php artisan queue:work --once
```

Automated tests (`phpunit.xml`) use `QUEUE_CONNECTION=sync` and `MAIL_MAILER=array` so workers are not required in CI.

### Why “Resend verification” showed nothing in `laravel.log`

1. `POST /api/v1/auth/email/verification-notification` was authorized and returned success.
2. `VerifyEmailNotification` is `ShouldQueue` + `afterCommit()`.
3. The job was pushed to **Redis**, not executed by the HTTP request.
4. Without `queue:work`, the log mailer never ran — so neither `laravel.log` nor `mail.log` received a message body.
5. Structured metadata `transactional_mail.notification_dispatched` is written at dispatch time (default log channel). Mail **bodies** arrive later via `MAIL_MAILER=log` → `storage/logs/mail.log`.

Inspect:

```bash
# Dispatch / lifecycle metadata (no tokens in structured context)
rg transactional_mail storage/logs/laravel.log

# Rendered local messages (after worker). Dev only — may contain signed URLs.
tail -f storage/logs/mail.log
```

Do not use production SES/SMTP for local verification.

**UAT tip:** a Redis backlog of stale notification jobs can make the next
`queue:work --once` render mail for a different recipient. Prefer matching
`To:` in `mail.log` to the expected address, or run
`php artisan queue:clear redis --force` before a controlled local probe
(destructive — only use on local Redis).


## Observability

Listener `App\Listeners\LogTransactionalMailLifecycle` is auto-discovered from `app/Listeners`
(do not also register it in `AppServiceProvider` — that duplicates events). It records safe metadata:

- `transactional_mail.notification_dispatched` (auth verification / password reset at `User` notify; default log channel)
- `transactional_mail.notification_sent` / `notification_failed` (`mh_mail` when configured)
- `transactional_mail.message_sending` / `message_sent` (subject + recipient count only)

Never logged intentionally: full bodies in structured logs, verification/reset tokens as discrete fields, SMTP credentials.

Rendered `MAIL_MAILER=log` bodies also write to `storage/logs/mail.log` (same channel). That file is **dev inspection only** and may contain signed URLs — do not treat it as a structured audit log.

## Queue / failed jobs

- Connection: Redis (`QUEUE_CONNECTION=redis` locally).
- Worker: `php artisan queue:work` (staging docs already require this).
- Failed jobs: Laravel `failed_jobs` table / `queue:failed` — no custom framework.
- Retries can re-send mail for notifications **without** idempotency keys (verification / password reset). BaseNotification domain events use DB idempotency for in-app + mail where keys are set.

## Inventory

| Event | Trigger | Recipient | Class | Channels | Queue | Template | Expected |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Email verification (register) | `AuthenticationService::register` → `User::sendEmailVerificationNotification` | New user | `VerifyEmailNotification` | mail | yes | MailMessage | Signed verify link |
| Email verification (resend) | `POST .../email/verification-notification` | Current user | same | mail | yes | same | New signed link |
| Password reset request | `POST .../forgot-password` | Matching user only | `ResetPasswordNotification` | mail | yes | MailMessage | FE reset URL + token |
| Account restricted/suspended/banned/restored | Admin status change (`AdminUserService`) | Target user | `AccountStatusChangedNotification` | mail only | yes | MailMessage | Status + admin reason |
| Commission due | Commission becomes due | Business + Ambassador | `CommissionNotification` (`commission_due`) | database + mail | yes | MailMessage | Amount, due date, CTA |
| Commission pre-deadline / deadline / overdue / follow-up | Reminder engine | Business (payment-pressure) | `CommissionNotification` | database + mail | yes | MailMessage | Reminder copy |
| Commission paid | Marked paid | Business + Ambassador | `CommissionNotification` (`commission_paid`) | database + mail | yes | MailMessage | Paid confirmation |
| Commission received | Marked received | Business + Ambassador | `CommissionNotification` (`commission_received`) | database + mail | yes | MailMessage | Received confirmation |
| Dispute opened / resolved | `DisputeNotificationDispatcher` | Dispute parties | `DisputeNotification` | database + mail | yes | MailMessage | Reference + deal id |
| Deal cancelled | `DealCancellationNotificationDispatcher` | Counterpart | `DealCancelledNotification` | database + mail | yes | MailMessage | Deal cancelled |
| Featured purchased | `CampaignFeaturedNotificationDispatcher` | Business owner | `CampaignFeaturedPurchasedNotification` | database + mail | yes | MailMessage | Package + expiry |
| Certification enrollment activated | After verified payment / enrollment | Ambassador | `CertificationEnrollmentActivatedNotification` | database + mail | yes | MailMessage | Programme confirmed |
| Certification assessment result | After assessment attempt scored & persisted (pass or fail) | Ambassador | `CertificationAssessmentResultNotification` | database + mail | yes | MailMessage | Pass/fail, score, next action |
| Certificate available | After PDF artifact generated | Ambassador | `CertificationCertificateAvailableNotification` | database + mail | yes | MailMessage | Certificate ready |
| Admin staff invitation | Staff invite create | Invitee email | `AdminStaffInvitationNotification` | mail | yes | MailMessage | Accept invite URL |

**Not implemented (mail):** change-password confirmation; campaign extension payment receipt; certification **assessment availability** dedicated email (SoT §39 lists it; deferred — not part of MH-BE-049); dedicated **Certification Award** email (SoT §39 lists it separately from assessment result / certificate available; Award is presented in-app without a dedicated notification); payment accounting receipts beyond enrollment/featured notifications above.

Password-reset email links to `{FRONTEND_URL}/reset-password?token=…&email=…`. The participant SPA implements `/forgot-password` and `/reset-password` (MH-FE-022).

Auth verification and password reset are **mail-only** (tokens must not enter the in-app notifications table).

## Authoritative vs implementation

| Product expectation (certs PRD / SoT) | Implementation |
| --- | --- |
| Confirmation notification/email after certification payment | Enrollment activated notification (mail + in-app) |
| Assessment result notification (pass/fail) | **Implemented** — `certification_assessment_result` (mail + in-app) per attempt, idempotent |
| Assessment available notification | **Deferred** — eligibility/state via API/UI only |
| Certification Award notification | **Deferred** — Award state via API/UI; no dedicated Award email |
| Certificate available email (FR-065) | Implemented |

## Production prerequisites (not configured here)

- Real `MAIL_MAILER` (SMTP/SES)
- Verified from-domain / DNS
- Always-on `queue:work` (or equivalent)
- Never commit production credentials
