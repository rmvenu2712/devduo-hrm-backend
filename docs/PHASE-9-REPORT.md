# Phase 9 — Learning and Skills

**Status: partial; lean catalog, enrollment and employee skills core is now routed.** The repository previously contained Phase 9 migrations and partial catalog/skill services without an HTTP API or frontend. This continuation connects a first employee and catalog-manager flow end to end.

## Implemented in this continuation

- Tenant-scoped, permission-gated Learning API routes for course catalog, course detail and versioned create/edit; course publishing/retirement; path listing/creation; session listing/scheduling; employee enrollment and learning history; skills catalog, proficiency levels, self skill profile/history and role gap analysis.
- Employees see published courses; catalog managers may see drafts. Self-enrollment rejects duplicate active/completed entries and unmet prerequisites, serializes concurrent enrollment through a course row lock, and applies the session waitlist/capacity policy. Approval-required courses are recorded as pending.
- Browser navigation and screens for catalog browse/publish, self-enrollment, learning history, path progress and self-declared skills/role gaps.
- Focused regression tests cover course visibility, publish/enroll, duplicate prevention, prerequisite enforcement and UI enrollment/skill declaration.

## Verification

- `cd backend && php vendor/phpunit/phpunit/phpunit --colors=never tests/Feature/Performance/LearningCoreTest.php` — **2 tests, 20 assertions passed**.
- `cd frontend && npm.cmd test -- --run src/pages/learning/__tests__/learning.test.jsx` — **2 tests passed**.
- `cd backend && php artisan route:list --path=learning` — **15 Learning routes registered**; PHP syntax checks passed.
- Frontend production build succeeded after the final publish action change; it reports a >500 kB chunk advisory.

## Database rollout

The development MariaDB reports both Phase 9 migrations as pending (2026_10_19_000001/000002). Feature tests migrate their isolated test database, but this continuation did not apply migrations to the shared development database. Apply pending project migrations before using the new API against that database.

## Deferred

Manager/HR assignment and enrollment decisions, attendance/completion, secure assessments and grading, certificates and renewal reminders, compliance assignments, skill taxonomy/category/role-requirement administration, manager skill validation and evidence, training requests/budgets, development-plan linkage, team dashboards/reports, content-provider integrations, and accessible content/session administration remain out of scope for this slice. See `docs/api/learning.md` for exact current API surface.

## Next

Phase 10 inspection found no implementation. Begin its HR helpdesk foundation; see `docs/PHASE-10-REPORT.md`.



