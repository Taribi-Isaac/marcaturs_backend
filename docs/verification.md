# Verification domain

MarcatursHub verification is a trust process that sits after identity (User) and profile (Business / Ambassador). It does not create campaigns, deals, payments, certification, or reputation.

## Concepts

- **Requirement**: administrator-defined check for `BUSINESS` or `AMBASSADOR` (`text`, `document`, `email`, `phone`, `other`). Active vs inactive, required vs optional, sort order, optional JSON `config`.
- **Submission**: one current row per user+requirement. Historical payloads live in `verification_submission_versions`; files in `verification_evidence`; decisions in `verification_review_events`.
- **Overall status**: derived from all **active required** requirements. `VERIFIED` only when every such requirement is `approved`. Zero required active requirements → `NOT_STARTED`.

`VERIFIED` is not an endorsement or a financial/legal guarantee.

## Storage

Private disk `sensitive` (local path `storage/app/private/sensitive`). Downloads only through the authenticated admin endpoint. Do not log document contents or identity numbers.

## Deferred

- Admin UI (APIs exist)
- External identity vendors (NIN/CAC APIs, OCR, biometrics)
- Public badge / directory
- Automated retention purge (config key only)
- Legal/compliance rules pending external counsel
