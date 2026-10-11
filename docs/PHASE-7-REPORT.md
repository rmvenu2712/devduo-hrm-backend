# Phase 7 — Onboarding, Offboarding & Employee Lifecycle

**Status: partial (lean-mode core implemented).** The repository contains the main lifecycle workflows and screens, but several Phase 7 requirements remain deferred or depend on later modules. This report reflects source inspection and the targeted tests listed below.

## Implemented

- Versioned onboarding/offboarding templates, generated lifecycle cases, task ownership, My Work integration, readiness, rescheduling, cancellation, and reminders.
- Separation event handling, offboarding checklist, encrypted exit interview submission and audited review, clearance checks, settlement handoff, alumni records, and rehire eligibility.
- Effective-dated internal moves with maker-checker approval, impact preview, and future-date application through housekeeping.
- Lifecycle dashboard, pre-joiner list, Action Center and data-quality/Employee 360 registrations.
- Frontend navigation and employee/manager/HR pages for tasks, exit interview, cases, templates, dashboard, pre-joiners, internal moves, and alumni.
- Move responses now expose flat employee compatibility fields and an authorization-aware `can_decide`; approver-only move lists apply the approver data scope. Case and pre-joiner pages now display the API's nested employee/readiness payloads, and dashboard counters use the backend's actual response fields.

## Files and schema

- Lifecycle domain: `backend/app/Domain/Lifecycle/**`, `backend/app/Http/Controllers/Lifecycle/LifecycleController.php`, and `backend/routes/lifecycle.php`.
- Lifecycle schema and permission grants: `backend/database/migrations/2026_10_17_000001_lifecycle_core.php` and `2026_10_17_000002_grant_lifecycle_permissions.php`.
- Frontend: `frontend/src/pages/lifecycle/**`, `frontend/src/routes/lifecycleRoutes.jsx`, `frontend/src/lib/lifecycleApi.js`, and app route/navigation registration.
- API contract: `docs/api/lifecycle-onboarding.md`.

## Verification

- `cd backend && php vendor/phpunit/phpunit/phpunit --colors=never tests/Feature/Lifecycle` — **16 tests, 220 assertions passed**.
- `cd frontend && npm.cmd test -- --run src/pages/lifecycle/__tests__/lifecycle.test.jsx` — **11 tests passed**.
- The first frontend run was blocked by PowerShell's npm script policy and then by Vite/esbuild sandbox access. Running `npm.cmd` with elevated access completed successfully.
- Frontend production build was not run in this continuation.

## Deferred or blocked

- Probation/confirmation, resignation initiation and withdrawal, rehire checklist, contract renewal, and extended-absence return workflows.
- Provisioning adapters, acknowledgments, secure form/document collection beyond the `document_ref` hook, bulk case assignment, task dependencies/branches, configurable SLAs, and lifecycle reports/exports.
- Real clearance providers for assets, expenses, loans, leave, and payroll; the current registry uses null providers until those modules connect.
- Production identity/account provisioning and other external integrations.

## Next

Review these deferred items against the requested lean-mode scope. If the current core is accepted for Phase 7, reconcile the stale Phase 5/6 task records before continuing to Phase 8; otherwise implement the selected Phase 7 workflow first.
