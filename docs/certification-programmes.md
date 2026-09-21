# Certification Programmes, Curriculum, Enrollment, Learning, Assessment, Awards & Certificates

Covers **MH-BE-CERT-01** through **MH-BE-CERT-10** (Ambassador profile certification representation).

## Implemented

- Programme + Programme Version lifecycle, fee/pass-mark fields, current published pointer
- Modules → Lessons → Resources under a Programme Version
- Deterministic ordering + reorder APIs
- Draft-only curriculum mutation; published/unpublished immutability
- Private downloadable resource storage (authorized Admin + enrolled Ambassador download)
- Admin RBAC: `certification.view`, `certification.manage`, `certification.learners.view`
- Ambassador published programme catalogue (metadata/fee only)
- Certification purchase via existing PlatformPayment / Paystack
- Idempotent enrollment activation bound to purchased Programme Version
- Enrollment-bound curriculum access (exact enrolled Programme Version)
- Lesson progress persistence + explicit Mark Complete (idempotent)
- Required-lesson assessment eligibility calculation
- Version-bound Final Assessment + Admin question management
- Eligible learner assessment preview (answer keys excluded)
- Assessment attempts with server-side equal-weight scoring and pass/fail
- Unlimited retakes without additional payment
- Certification Award created from first qualifying passing attempt
- Certificate record registered from Award (one Certificate per Award)
- Asynchronous Certificate PDF artifact generation on private storage + authorized download
- Ambassador profile `certification` representation derived from Awards (self + Admin user detail)
- `certification_admin_events` audit
- Enrollment activated + certificate available notifications (PDF-ready)
- Assessment result notification (pass/fail) after each committed attempt (MH-BE-049)

## Deferred (later slices)

Public verification, QR, profile marketplace discovery of certified ambassadors (FR-049 / Phase 2), ranking/featured boosts, refunds, undo/reset of lesson completion, additional question types, post-submit answer-key review, award/certificate revocation, branded certificate designer / Counsel-approved legal wording.

## Enrollment & Billing (MH-BE-CERT-03)

```text
Ambassador → published programme
  → server resolves current published version + fee
  → PlatformPayment purpose=certification_enrollment (Paystack)
  → verify/webhook (server-authoritative)
  → CertificationEnrollment ACTIVE bound to purchased version
```

### Rules

- Payment ≠ certification; enrollment ≠ certification.
- Fee/currency come from the Programme Version at **initialize** and are snapshotted on `platform_payments`.
- Enrollment stores immutable `programme_version_id` + historical `fee_amount_minor`.
- One enrollment per `(user_id, programme_id)` (unique). Duplicate purchase while enrolled → `409`.
- Pending purchases are `platform_payments` with `status=pending` (no `PENDING_PAYMENT` enrollment row).
- Atomic activation: payment `paid` + enrollment create in one DB transaction; unique on `platform_payment_id`.
- Recovery: re-run verify/webhook if payment succeeded without enrollment.

### Ambassador APIs

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/api/v1/certification/programmes/{id}/purchase/initialize` | Start Paystack purchase |
| POST | `/api/v1/certification/purchases/verify` | Confirm reference → enrollment |
| GET | `/api/v1/certification/enrollments` | Own enrollments |
| GET | `/api/v1/certification/enrollments/{id}` | Own enrollment |
| GET | `/api/v1/certification/enrollments/{id}/curriculum` | Enrolled version curriculum + eligibility |
| GET | `/api/v1/certification/enrollments/{id}/progress` | Lesson progress + eligibility |
| POST | `/api/v1/certification/enrollments/{id}/lessons/{lesson}/complete` | Mark Complete (idempotent) |
| GET | `/api/v1/certification/enrollments/{id}/lessons/{lesson}/resources/{resource}/download` | Protected resource download |
| GET | `/api/v1/certification/enrollments/{id}/assessment` | Version-bound assessment (+ questions only when eligible) |

### Admin APIs

| Method | Path | Permission |
|--------|------|------------|
| GET | `/api/v1/admin/certification/enrollments` | `certification.learners.view` |
| GET | `/api/v1/admin/certification/enrollments/{id}` | `certification.learners.view` |

Webhook routing reuses `POST /api/v1/webhooks/paystack` via `platform_payments.purpose`.

### Notifications

`certification_enrollment_activated` (email + in-app) after successful activation (PRD FR-060/061 combined confirmation).

### Refunds

Not implemented — Product/Counsel policy still open.

## Permissions

| Permission | SUPER_ADMIN | OPERATIONS | VERIFICATION | MODERATION |
|------------|:-----------:|:----------:|:------------:|:----------:|
| `certification.view` | ✓ | ✓ | — | — |
| `certification.manage` | ✓ | ✓ | — | — |
| `certification.learners.view` | ✓ | ✓ | — | — |

## Domain model

```text
CertificationProgramme
  ├── status: draft | published | unpublished | archived
  ├── current_published_version_id
  └── versions[]
        ├── version_number, status, fee_amount_minor, fee_currency, pass_mark_percent
        └── modules[] (title, description, sort_order)
              └── lessons[] (title, description, content_type, is_required, sort_order)
                    └── resources[] (type, title, sort_order, body_text | external_url | private file)
```

Curriculum always belongs to a specific Programme Version. Version 1 curriculum remains intact after Version 2 is published.

## Version / curriculum immutability

| Version status | Fee / pass mark | Curriculum |
|----------------|-----------------|------------|
| `draft` | mutable | mutable |
| `published` | immutable | immutable |
| `unpublished` | immutable | immutable |

## Ordering

Server assigns `sort_order = max+1` on create. Reorder endpoints require an exact permutation of IDs and rewrite `1..n` in a transaction. Unique `(parent_id, sort_order)` constraints apply.

## Lesson content types (SoT §17 / FR-016)

`video` | `text` | `downloadable` | `external_reference`

## Resource types

| Type | Reference model |
|------|-----------------|
| `text` | `body_text` |
| `video` | `external_url` (no video SaaS required) |
| `external_reference` | `external_url` |
| `downloadable` | private file on `config('certification.resource_disk')` (default `sensitive`) |

API responses never expose `disk`/`path`.

## Permissions

| Permission | SUPER_ADMIN | OPERATIONS | VERIFICATION | MODERATION |
|------------|:-----------:|:----------:|:------------:|:----------:|
| `certification.view` | ✓ | ✓ | — | — |
| `certification.manage` | ✓ | ✓ | — | — |

## Admin APIs

### Programme / version (MH-BE-CERT-01)

| Method | Path | Permission |
|--------|------|------------|
| GET/POST | `/api/v1/admin/certification/programmes` | view / manage |
| GET/PATCH | `/api/v1/admin/certification/programmes/{id}` | view / manage |
| GET/POST | `.../versions` | view / manage |
| GET/PATCH | `.../versions/{version_number}` | view / manage |
| POST | `.../versions/{version_number}/publish\|unpublish` | manage |

### Curriculum (MH-BE-CERT-02)

Base: `/api/v1/admin/certification/programmes/{programme}/versions/{version_number}`

| Method | Path suffix | Permission |
|--------|-------------|------------|
| GET/POST | `/modules` | view / manage |
| GET/PATCH/DELETE | `/modules/{module}` | view / manage |
| POST | `/modules/reorder` | manage |
| GET/POST | `/modules/{module}/lessons` | view / manage |
| GET/PATCH/DELETE | `/modules/{module}/lessons/{lesson}` | view / manage |
| POST | `/modules/{module}/lessons/reorder` | manage |
| GET/POST | `.../lessons/{lesson}/resources` | view / manage |
| GET/POST/DELETE | `.../resources/{resource}` | view / manage |
| GET | `.../resources/{resource}/download` | view |
| POST | `.../resources/reorder` | manage |

Resource update uses **POST** (multipart-friendly). Nested routes use scoped bindings + service chain checks.

## Ambassador APIs

| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/v1/certification/programmes` | Published programmes only |
| GET | `/api/v1/certification/programmes/{id}` | Fee from current published version; no pass mark |
| GET | `/api/v1/certification/enrollments/{id}/curriculum` | Own active enrollment → enrolled version curriculum |
| GET | `/api/v1/certification/enrollments/{id}/progress` | Per-lesson progress + assessment eligibility |
| POST | `/api/v1/certification/enrollments/{id}/lessons/{lesson}/complete` | Explicit Mark Complete |
| GET | `.../lessons/{lesson}/resources/{resource}/download` | FR-023 enrolled download |

## Learning progress (MH-BE-CERT-04)

```text
Active Enrollment → enrolled Programme Version → Modules → Lessons
  → explicit Mark Complete → lesson progress (completed)
  → eligibility = all required lessons completed
```

### Access rules

- Actor: authenticated Ambassador + `account.access` (no certification-specific exceptions).
- Restricted / suspended / banned follow global middleware (restricted cannot access learning APIs).
- Curriculum is resolved from `enrollment.programme_version_id`, never from `current_published_version_id`.
- Older enrolled versions remain accessible even if a newer version is published or the enrolled version is later unpublished.
- IDOR: non-owners receive `404`; Business / unauthenticated denied; lesson must belong to the enrolled version.

### Progress model

Persisted on `certification_lesson_progress` with unique `(enrollment_id, lesson_id)`.

Conceptual statuses: `not_started` | `in_progress` | `completed`.

- Lessons without a progress row are treated as `not_started`.
- Opening a lesson does **not** create progress or complete the lesson (FR-019/FR-022).
- Mark Complete moves a lesson to `completed` (may go directly from `not_started` → `completed`; `in_progress` is reserved for a future interaction if Product requires it).
- Completion is **idempotent**; `completed_at` is not rewritten on retries.
- No undo/reset endpoint (not specified by Product — intentionally omitted).

### Assessment eligibility

Derived (not cached):

- `required_lessons` / `completed_required_lessons`
- `optional_lessons` / `completed_optional_lessons`
- `eligible` when `completed_required_lessons === required_lessons`

Optional incomplete lessons do **not** block eligibility. Assessment attempts, scoring, awards, and certificates are **not** implemented in this slice.

### Audit

`lesson_completed` on `certification_admin_events` (payload: enrollment/lesson/progress ids + timestamp). Content bodies and private paths are not logged.

## Final Assessment foundation (MH-BE-CERT-05)

```text
Programme Version
  └── CertificationAssessment (exactly one; unique programme_version_id)
        └── CertificationQuestion (ordered)
              └── CertificationQuestionOption (ordered; is_correct admin-only)
```

### Authoritative rules applied

- Assessment belongs to a Programme Version (SoT entity model / FR-045).
- Learners resolve assessment via `enrollment.programme_version_id`, never `current_published_version_id`.
- Eligibility reuses MH-BE-CERT-04 required-lesson calculation (FR-025).
- **Pass mark authority (MH-BE-048):** `CertificationProgrammeVersion.pass_mark_percent` is the sole configurable source (SoT §22 / §30). Assessment does not store an independent pass mark; API responses expose the version value as derived read-only. Attempts snapshot the version pass mark at start for scoring. Not hard-coded `70%`.
- Version publish/unpublish freezes assessment content the same way curriculum is frozen (draft-only mutation).
- Question type for this release: `single_choice` only (FR-028 technical/product implementation decision). Other types remain Product-open and are rejected.
- Single-choice requires ≥2 options and exactly one `is_correct`.

### Admin APIs

Base: `/api/v1/admin/certification/programmes/{programme}/versions/{version}`

| Method | Path suffix | Permission |
|--------|-------------|------------|
| GET/POST/PATCH | `/assessment` | view / manage |
| GET/POST | `/assessment/questions` | view / manage |
| GET/PATCH/DELETE | `/assessment/questions/{question}` | view / manage |
| POST | `/assessment/questions/reorder` | manage |

Admin representations include `is_correct`. Audit events: `assessment_created`, `assessment_updated`, `question_created`, `question_updated`, `question_deleted`, `questions_reordered` (no answer-key payloads).

### Learner API

| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/v1/certification/enrollments/{id}/assessment` | Own active enrollment; `available` mirrors CERT-04 eligibility |

When `available=false`, questions are omitted (metadata + `question_count` + eligibility remain). When `available=true`, questions/options are returned **without** `is_correct`.

### Explicitly not in this slice

Attempts, start/submit, scoring, pass/fail persistence, retakes, awards, certificates, timed exams, review-before-submit, answer-key reveal after submit.

## Assessment attempts & scoring (MH-BE-CERT-06)

```text
Eligible enrollment
  → POST .../assessment/attempts  (start or resume one in-progress)
  → POST .../attempts/{id}/submit (answers[])
  → server scores → immutable submitted result
  → optional further attempts (unlimited; no extra payment)
```

### Pass-mark authority

- **Scoring threshold** = immutable attempt snapshot `certification_assessment_attempts.pass_mark_percent`, copied at **start** from the enrolled Programme Version’s authoritative `pass_mark_percent` (MH-BE-048).
- Assessment rows no longer store `pass_mark_percent`; live assessment configuration cannot diverge from the version for new attempts.
- Programme Version `pass_mark_percent` remains the CERT-01 **publish prerequisite** and Admin configuration surface.

### Scoring (implementation decision)

Equal-weight single-choice (no authoritative weighted model):

```text
score_percent = round_half_up((correct_count / total_questions) * 100, 2)
passed = score_percent >= attempt.pass_mark_percent   (bccomp, scale 2)
```

Integer arithmetic avoids floating-point boundary drift. Missing/extra/cross-question answers are rejected (`400`). Unanswered questions are not allowed — submit must include every question exactly once.

### Attempt lifecycle

| Status | Meaning |
|--------|---------|
| `in_progress` | Started; not scored; resumable |
| `submitted` | Scored; immutable |

Rules:

- At most **one** `in_progress` attempt per enrollment+assessment (start resumes it).
- Unlimited `submitted` history (FR-031/FR-033), including after a pass (no post-pass lock invented).
- Duplicate submit returns the existing submitted result (idempotent).
- Published version immutability + FKs to question/option provide historical answer interpretability; pass mark is snapshotted.

### Learner APIs

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/v1/certification/enrollments/{id}/assessment/attempts` | Attempt history |
| POST | `/api/v1/certification/enrollments/{id}/assessment/attempts` | Start / resume |
| GET | `/api/v1/certification/enrollments/{id}/assessment/attempts/{attempt}` | Attempt detail |
| POST | `/api/v1/certification/enrollments/{id}/assessment/attempts/{attempt}/submit` | Submit + score |

Learner payloads never include `is_correct` / answer keys.

### Admin APIs

| Method | Path | Permission |
|--------|------|------------|
| GET | `/api/v1/admin/certification/enrollments/{id}/assessment/attempts` | `certification.learners.view` |
| GET | `/api/v1/admin/certification/enrollments/{id}/assessment/attempts/{attempt}` | `certification.learners.view` |

Admin attempt detail may include per-answer `is_correct` for monitoring (FR-058).

### Audit

`assessment_attempt_started`, `assessment_attempt_submitted`, `assessment_attempt_passed`, `assessment_attempt_failed` (scores/ids only; no answer keys).

## Certification Award (MH-BE-CERT-07)

```text
Submitted passing Attempt
  → FR-036 re-check required lessons
  → create Certification Award (once per user + programme version)
  → register Certificate record (MH-BE-CERT-08; PDF deferred)
```

### Meaning

The Award is the formal achievement record (SoT §26): Ambassador + Programme + Programme Version + successful assessment attempt. It is **not** the Certificate artifact, verification badge, reputation, or ranking signal. **Award ≠ Certificate.**

### Trigger

Created **transactionally** inside CERT-06 assessment submit when `passed=true`, after FR-036 eligibility re-check. Never from client-supplied pass flags.

### Uniqueness

| Constraint | Rule |
|------------|------|
| `(user_id, programme_version_id)` | One Award per Ambassador per Programme Version |
| `assessment_attempt_id` | Award references exactly one attempt |
| `enrollment_id` | One Award per enrollment |

If multiple later passes occur, **no additional Award** is created. The Award continues to reference the **first** qualifying passing attempt that created it.

### Lifecycle

Status: `awarded` only. No update/delete/revoke learner or Admin mutation endpoints in MVP. Revocation remains future Product work.

### Learner APIs

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/v1/certification/awards` | Own awards |
| GET | `/api/v1/certification/awards/{id}` | Own award detail |

### Admin APIs

| Method | Path | Permission |
|--------|------|------------|
| GET | `/api/v1/admin/certification/enrollments/{id}/awards` | `certification.learners.view` |
| GET | `/api/v1/admin/certification/awards/{id}` | `certification.learners.view` |

### Explicitly deferred from Award slice

PDF generation/download; public verification/QR; profile “Certified” badge; award revocation.

## Certificate (MH-BE-CERT-08 / MH-BE-CERT-09)

```text
Certification Award
  → Certificate record (register immediately with Award; status=issued)
  → after commit: queue PDF generation
  → private storage artifact
  → downloadable via authorized endpoints
```

### Meaning

The Certificate is the durable evidence record issued for an Award. The PDF is a **reproducible artifact** of that record. The Award remains the authoritative qualification. PDF generation failure must not erase or invalidate the Award or Certificate (FR-069).

### Trigger

Certificate record registered **immediately after Award creation** (and on Award recovery paths) inside the assessment-submit transaction.

PDF generation is **asynchronous** (`GenerateCertificationCertificateArtifactJob`) via `DB::afterCommit` so generation never participates in the Award/Certificate commit.

### Artifact lifecycle

| `artifact_status` | Meaning |
|-------------------|---------|
| `pending_generation` | Record issued; PDF not ready |
| `generated` | PDF stored on private disk |
| `failed_retryable` | Generation/storage failed; Certificate remains `issued` |

`Certificate.status` stays `issued` regardless of artifact state. Admin may `POST .../artifact/retry` (`certification.manage`).

### Uniqueness

| Constraint | Rule |
|------------|------|
| `award_id` UNIQUE | Exactly one Certificate per Award |
| `certificate_number` UNIQUE | Server-generated opaque identifier (`mhcert_{ulid}`); never regenerated on PDF retry |

### Content / PDF source

PDF is rendered **only** from Certificate snapshots:

- `recipient_name`, `programme_name`, `programme_version_number`, `issuer_name`, `issued_at`, `certificate_number`, status Issued

Does **not** include email, phone, private profile fields, QR, public verification URL, or storage paths. Layout uses a minimal deterministic PDF writer (no third-party PDF package). Counsel-approved legal wording remains open.

### Storage

Private disk `config('certification.certificate_disk')` (default `sensitive`). Paths are server-side only (`certification/certificates/{id}/{ulid}.pdf`).

### Learner APIs

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/v1/certification/certificates` | Own certificates |
| GET | `/api/v1/certification/certificates/{id}` | Own certificate detail (`artifact_status`, `artifact_available`) |
| GET | `/api/v1/certification/certificates/{id}/download` | Download PDF when `generated`; `409` if pending/failed |

### Admin APIs

| Method | Path | Permission |
|--------|------|------------|
| GET | `/api/v1/admin/certification/enrollments/{id}/certificates` | `certification.learners.view` |
| GET | `/api/v1/admin/certification/certificates/{id}` | `certification.learners.view` |
| GET | `/api/v1/admin/certification/certificates/{id}/download` | `certification.learners.view` |
| POST | `/api/v1/admin/certification/certificates/{id}/artifact/retry` | `certification.manage` |

### Notifications

`certification_certificate_available` fires when the PDF artifact is **successfully generated** (not merely when the Certificate row is created). Does **not** email a PDF attachment.

### Explicitly deferred

Public verification; QR; expiry; revocation/reissue; marketplace discovery/ranking of certified ambassadors; branded designer / Counsel-approved legal template text.

## Frontend (MH-FE-CERT-01)

Ambassador-facing UI lives in `frontend/src/features/ambassador-certification/` and is documented in `frontend/docs/certification.md`.

Entry: Ambassador nav **Certification**, Discover CTA, Settings certification summary, notification deep-links. Journey consumes the learner APIs above. FR-049 discovery remains deferred.

## Ambassador profile certification (MH-BE-CERT-10)

```text
Certification Award (authoritative)
  → computed profile.certification (no duplicated is_certified column)
```

### Certified definition

An Ambassador is represented as certified **only when at least one `CertificationAward` with status `awarded` exists** for that user.

Not sufficient: payment, enrollment, lesson completion, assessment eligibility, in-progress/failed attempts, or PDF artifact availability.

### Visibility (v1)

| Audience | Certification on profile |
|----------|--------------------------|
| Ambassador (`GET /api/v1/ambassadors/me`) | Yes |
| Admin user detail (`GET /api/v1/admin/users/{id}` → `profile.certification`) | Yes |
| Public marketplace / Business discovery | Deferred (FR-049 / Phase 2) |
| Guest / other Ambassadors via public profile API | No public Ambassador profile API exists |

### Display

```json
"certification": {
  "is_certified": true,
  "label": "Certified MarcatursHub Ambassador",
  "awards": [
    {
      "id": 1,
      "programme_id": 1,
      "programme_version_id": 1,
      "programme_name": "...",
      "programme_version_number": 1,
      "awarded_at": "...",
      "certificate_id": 1
    }
  ]
}
```

Label wording follows Certification SoT §34. Distinct from identity verification. Does **not** imply marketplace ranking, performance guarantees, or legal accreditation.

Historical `programme_name` / `programme_version_number` prefer Certificate snapshots when present; otherwise Award → Programme Version.

`certificate_id` links to private Certificate APIs when a Certificate record exists; missing PDF does not hide certification.

### Explicitly not implied

Marketplace ordering, Featured, commission rates, Deal eligibility, public certificate verification, reputation/scoring.

## Publish rules (programme version)

Requires configured `fee_amount_minor` and `pass_mark_percent`. Curriculum completeness is **not** enforced (Product launch-readiness decision — do not invent content minimums).

## Audit

Programme/version: `programme_*`, `version_*`  
Curriculum: `module_*`, `modules_reordered`, `lesson_*`, `lessons_reordered`, `resource_*`, `resources_reordered`  
Enrollment: `purchase_initialized`, `enrollment_activated`  
Learning: `lesson_completed`  
Assessment: `assessment_created`, `assessment_updated`, `question_created`, `question_updated`, `question_deleted`, `questions_reordered`  
Attempts: `assessment_attempt_started`, `assessment_attempt_submitted`, `assessment_attempt_passed`, `assessment_attempt_failed`  
Awards: `award_created`  
Certificates: `certificate_created`, `certificate_artifact_generated`, `certificate_artifact_generation_failed`, `certificate_artifact_downloaded`
