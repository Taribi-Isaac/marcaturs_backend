# Verification domain

MarcatursHub verification is a trust process that sits after identity (User) and profile (Business / Ambassador). It does not create campaigns, deals, payments, certification, or reputation.

## Concepts

- **Requirement**: administrator-defined check for `BUSINESS` or `AMBASSADOR` (`text`, `document`, `email`, `phone`, `other`). Active vs inactive, required vs optional, sort order, optional JSON `config`.
- **Submission**: one current row per user+requirement. Historical payloads live in `verification_submission_versions`; files in `verification_evidence`; decisions in `verification_review_events`.
- **Overall status**: derived from all **active required** requirements. `VERIFIED` only when every such requirement is `approved`. Zero required active requirements → `NOT_STARTED`.

`VERIFIED` is not an endorsement or a financial/legal guarantee.

Email verification (`email_verified_at`) is separate from this checklist. It is implemented for account contact trust but does **not** gate normal product API access (see account-status middleware).

`phone` and `email` requirement types collect participant-provided values as text-like submissions. They are **not** platform OTP/SMS challenges.

## Admin console

Authorized staff with `verification.configure` (Super Admin / Operations) manage requirements in Admin Control (`/verification/requirements`). Staff with `verification.view` / `verification.review` may list definitions and review submissions but cannot create or edit requirements.

When no active requirements exist for a participant type, participants receive HTTP 200 with `overall_status=NOT_STARTED` and an empty checklist — not an error.

## Storage

Private disk `sensitive` (local path `storage/app/private/sensitive`). Downloads only through the authenticated admin endpoint. Do not log document contents or identity numbers.

## Deferred

- External identity vendors (NIN/CAC APIs, OCR, biometrics)
- Public badge / directory
- Automated retention purge (config key only)
- Legal/compliance rules pending external counsel
- Phone/SMS OTP providers
- Making email verification a universal account-access gate (product decision)
