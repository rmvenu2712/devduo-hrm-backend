# Phase 6 — Recruitment, ATS & Hiring

Scope: Phase 6 in `hrms_master_16_phases.md`. API: `docs/api/recruitment.md`. Evidence/gates: `docs/PHASE-6-REPORT.md`.

**Current classification: partial. Updated 2026-10-11.**

| ID | Work | Status |
| --- | --- | --- |
| P06-T01 | Inspect core workflows, scope and privacy | Done |
| P06-T02 | Verify backend and recruiter UI | Done: Recruitment + Lifecycle 34 tests / 617 assertions; frontend 13 tests; production build passed |
| P06-T03 | Update status, API contract and report | Done |
| P06-T04 | Existing-person rehire with identity confirmation, People scope and eligibility | Done; backend/frontend tests |
| P06-T05 | Interview rescheduling, UTC interpretation, conflict/stale checks and panel notices | Done; backend/frontend tests |
| P06-T06 | Tenant-filter panel selection and notification holders | Done; regression tests |
| P06-T07 | Local offer/posting expiry and repeat-safe stage/feedback reminders | Done; command, migration and expiry/privacy/idempotency tests |
| P06-T08 | Candidate import preview/commit with consent and duplicate review | Done: backend `CandidateImportTest` 5 tests / 29 assertions; Recruitment + Lifecycle 39 tests / 646 assertions; UI see report |
| P06-T09 | Private resumes and offer letter documents (backend) | Done: Recruitment + Lifecycle 51 tests / 871 assertions. Resume/offer-document UI and the career/candidate portal remain (P06-T10) |
| P06-T10 | Resume panel, offer-document modal/print view and offer-template admin UI | Done: Recruitment frontend 29 tests; production build passed |
| P06-T11 | Configurable pipelines/SLAs and requisition versions (backend + UI) | Done: Recruitment + Lifecycle 67 tests / 1113 assertions; Recruitment frontend 44 tests; production build passed |
| P06-T12 | Careers page/candidate portal (public-surface security review first), job-level default pipeline, e-sign/email adapters | Pending (provider/legal-gated parts cannot be verified locally) |

Other gaps: configurable pipelines/SLAs, job/requisition versions, assessments/kits, talent pools/referrals/agencies, internal conversion, communications and broader analytics. Fixed reminders do not complete configurable SLA management.

Migration ran only in isolated tests. Deploy the reminder migration and scheduler, then verify MariaDB locking. Provider adapters/credentials/acceptance tests and country-specific legal/privacy review remain gates. See report for details.

Candidate import is done; continue with P06-T12 (candidate portal needs a public-surface security review); do not skip to Phase 8 or label the full phase complete.
