# Phase 7 — Onboarding, Offboarding & Employee Lifecycle

Scope: Phase 7 in `docs/HRMS-MASTER-16-PHASES.md`; API contract: `docs/api/lifecycle-onboarding.md`.
This phase was absent from the previous progress/status files. Current classification is based on source inspection and the targeted test results below.

## Current implementation classification

| Area | Status | Evidence / remaining work |
| --- | --- | --- |
| Versioned onboarding/offboarding templates and checklist tasks | Implemented (core) | `TemplateService`, `CaseService`, lifecycle API and template UI. Dependencies, branches, SLA configuration and bulk assignment are deferred. |
| Onboarding/offboarding cases, readiness, task ownership and My Work | Implemented (core) | `CaseService`, `LifecycleController`, domain-event boot hooks and case/task UI. Case and pre-joiner UI now follow the nested API payloads. Secure forms, acknowledgments and provisioning adapters are deferred. |
| Separation, exit interview, clearance and settlement handoff | Implemented (core) | `OffboardingService`, encrypted interview model and lifecycle API. Real asset/expense/loan/leave/payroll clearance providers remain null adapters. |
| Internal moves with maker-checker and effective dating | Implemented (core); API/UI response mismatch fixed and verified | `MoveService`, feature tests, `MovesPage`. API exposes the flat employee fields and `can_decide`; approver-only list scoping uses the approver permission. |
| Alumni and rehire eligibility | Implemented (core) | Lifecycle controller and `AlumniPage`; People rehire flow consumes eligibility. |
| Lifecycle dashboard, quality rules and Employee 360 | Implemented (core) | Dashboard/controller and `LifecycleModuleBoot` registrations. |
| Frontend route/navigation and core screens | Implemented (core) | Registered in `main.jsx` and `Layout.jsx`; lifecycle pages and targeted UI tests cover API payloads. |
| Full Phase 7 scope | Partial | Probation/confirmation workflows, resignation initiation, rehire checklist, provisioning/acknowledgment, secure collection, bulk operations, reports, and several integrations remain deferred or absent. |

## Tasks

| ID | Task | Status |
| --- | --- | --- |
| P07-T01 | Inspect existing lifecycle backend, API contract, frontend and tests; classify scope | DONE (inspection) |
| P07-T02 | Align internal move API response with UI expectations and approver data scope | DONE; covered by backend assertions and frontend test |
| P07-T03 | Verify Lifecycle backend and frontend tests; fix regressions | DONE: backend Lifecycle folder 16 tests / 220 assertions; frontend Lifecycle file 11 tests |
| P07-T04 | Reconcile remaining Phase 7 scope and write phase report/status after verification | PARTIAL: scope and API docs reconciled; broader workflows remain deferred |

## Next action

Review deferred Phase 7 workflows and decide which belongs in lean-mode core before advancing to Phase 8.
