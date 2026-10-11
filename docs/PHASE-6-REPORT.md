# Phase 6 — Recruitment, ATS & Hiring

**Status: partial against the full master specification. Updated 2026-10-11.**

## Implemented

Existing core: requisitions/headcount, jobs/postings, candidate consent/retention and duplicates, scoped pipelines/history, interviews/independent scorecards, versioned maker-checker offers, analytics, People conversion, recruiter UI and shared audit/task/notification integrations.

This continuation adds:
- Existing-person rehire handover with former-employee picker and explicit identity confirmation. Requires People manage/rehire permissions and rehire scope; existing separation, latest-employment and eligibility rules apply. Overrides require a reason. Reuses the Person and increments employment sequence. Accepted-offer/headcount/idempotency/onboarding behavior is preserved.
- Interview rescheduling with reason, expected original start, future/state checks, panel conflict detection and stale-edit protection. UTC storage is now read as UTC. Panel/tasks are preserved; rescheduling and cancellation notify panelists in-app and are audited.
- Tenant filters on panel selection and recruitment notification holders.
- Hourly local housekeeping: open offers and published postings expire after their UTC expiry date; accepted offers remain accepted. Expiry closes offer-review tasks. Applications waiting 30 days receive one recruiter reminder per stage; missing feedback receives one panelist reminder after 24 hours. Stage transitions reset reminders. Closed applications, anonymized/expired-retention candidates and inactive/unauthorized recipients are skipped. Disabled recruitment tenants are skipped. Repeat runs do not resend completed reminders.

## Verification

- Backend: `php vendor/phpunit/phpunit/phpunit tests/Feature/Recruitment tests/Feature/Lifecycle` — **67 tests, 1113 assertions passed** (rerun after candidate import, resume/offer-document and pipeline/SLA/version work).
- Frontend: `npm test -- --run src/pages/recruitment` — **44 tests passed** (includes candidate import, resume/offer-document and pipeline/version UI). Production build passed.
- Production build passed; existing JavaScript bundle warning above 500 kB remains.
- Pint formatted changed PHP files; final backend tests ran afterward.
- Tests cover rehire identity/permission/scope/eligibility/idempotency, interview conflicts/stale edits/tenant checks, expiry boundaries, accepted-offer preservation, reminder idempotency/privacy/closed-state suppression, disabled modules and Lifecycle integration.
- Vite tests/build required approved sandbox escalation for filesystem access. Browser, live providers and production database concurrency were not verified.

## Candidate CSV import (added)
Tenant-scoped, creator-owned preview then explicit commit (`docs/api/recruitment.md`). Reuses `CandidateService` validation (consent reference/date, privacy notice, retention <= 2 years), per-row duplicate detection (in-file and existing candidates by blind-index email hash), audit entries, a 24 h preview expiry, and discards source rows after commit. Limits: 500 rows, 1 MB, fixed template headers, no column mapping, no update-on-duplicate (duplicates are skipped for separate review). Tests: `tests/Feature/Recruitment/CandidateImportTest.php` (5 tests / 29 assertions) cover preview-without-writes, confirmation requirement, partial import, duplicate handling, bad headers, permission, creator ownership and expiry. Migration `2026_10_26_000002_recruitment_imports.php` was exercised only in isolated test databases.

## Private resumes and offer documents (added, backend)
- Resumes: pdf/doc/docx up to 5 MB validated by extension, real content type and container (shared People `FileInspector`); random names on the private `hrm_private` disk; versioned (max 20) per candidate; downloads stream as attachments through authorized, audited endpoints scoped by `HiringScope`; no content parsing; no antivirus hook exists in the codebase (gate below). Anonymization and retention housekeeping delete files and letter snapshots (repeat-safe, hired candidates skipped).
- Offer documents: versioned restricted-HTML templates with 8 allowed merge fields (escaped substitution, no code execution). Approving an offer creates an encrypted immutable snapshot when an active template exists; sending creates one only if none exists; regeneration appends versions. The print view has a strict CSP and is audited. Compensation text shows only to `payroll.compensation.view` holders at view time.
- Tests: `ResumeTest` (5) and `OfferDocumentTest` (7). Migration `2026_10_27_000001_recruitment_documents.php` ran only in isolated SQLite tests; shared MariaDB was not migrated.
- UI (P06-T10): resumes panel on the candidate profile, offer-documents modal with print view (opens via authenticated fetch into a blob window, so the server CSP header does not apply to it; content is server-escaped), and an offer-template admin page. Frontend recruitment suite: 29 tests passed.
- Not built: antivirus scanning, e-signature, email delivery, PDF generation, resume parsing, template edit/retire endpoints, candidate/public portal.

## Configurable pipelines, SLAs and requisition versions (added, P06-T11)
- Pipelines: tenant-defined versioned stage lists (kind active|offer|hired|rejected, per-stage SLA hours and reminder recipients, pipeline-level forward-skip flag). A default pipeline matches the earlier fixed stages; a version is immutable once a requisition uses it (409 -> create new version). Moves validate against the requisition's pipeline (no backward moves; hired/rejected unreachable by plain moves). Assignment is per requisition via `pipeline_code` (no job-level default yet).
- SLAs: reminders use per-stage `sla_hours` (fallback to the old 30-day default), once per application and stage, with the earlier suppressions; feedback reminder window is `feedback_reminder_hours` on the pipeline. Analytics `sla` reports breaches and average hours per stage.
- Requisition versions: amendments create numbered versions with before/after, reason and actor; higher openings, higher budget or a currency change on an open requisition need a different approver before going live; filled counts, applications and offers are untouched.
- UI: pipelines admin with stage editor and frozen-version flow, pipeline selector, Amend dialog and Versions drawer with approval, pipeline-driven board columns, SLA analytics card.
- Verification: Recruitment + Lifecycle backend 67 tests / 1113 assertions; Recruitment frontend 44 tests; production build passed. Migrations `2026_10_28_000001/2` ran only on SQLite.
- Known follow-ups: the requisition list returns only `pipeline_code`, so a requisition on an older pipeline version may render the active version's stages on the board (add pipeline version to the list payload); actor names show as `user #id`; approve/reject disabling depends on `session.user.id`.

## Deployment and external gates

- Apply `2026_10_26_000001_recruitment_reminders.php` through normal deployment before housekeeping or stage transitions. Migration ran only in isolated tests; shared MariaDB was not migrated.
- Enable Laravel scheduler for `recruitment:housekeeping {--tenant=}`. Production database locking and scheduler operation require deployment verification. Reminder thresholds are fixed defaults, not configurable pipeline SLAs.
- Calendar, email, job-board, e-sign and background-check integrations require implemented adapters, credentials and provider acceptance tests. In-app notices/local posting expiry do not deliver candidate messages or remove external adverts.
- Country-specific consent purpose, retention, recruitment fairness, background checks and offer terms require qualified legal/privacy review. Local tests do not certify compliance.

## Remaining product gaps and next work

Career site/candidate portal and extended profiles/resume parsing; job/requisition versions and conditional approvals; configurable pipelines/questions/SLAs and bulk actions; talent pools/referrals/agencies; assessments/kits; candidate communications/history; e-sign; internal-employee conversion and fuller readiness/compensation/invitation integration; broader analytics/saved views.

Next practical Phase 6 work (P06-T12): the candidate portal (needs a public-surface security review) and job-level default pipeline; provider-gated items (calendar, email, job boards, e-sign, background checks, antivirus) cannot be verified locally. Phase 6 is not complete.
