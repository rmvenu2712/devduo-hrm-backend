# Phase 8 — Performance Management

**Status: partial; lean-mode goals and review core is implemented.** The backend domain and API already covered broader performance workflows. This continuation added the main browser flows and reviewer response submission; administration and adjacent workflows remain incomplete in the UI.

## Implemented and verified

- Goals: browse visible goals, create a personal goal and submit progress check-ins.
- Cycles: list, create, publish and launch a review cycle for users with cycle-management permission.
- Reviews: browse assigned/scoped reviews, inspect privacy-filtered response data, submit an assigned self/manager/peer response using the cycle template, and acknowledge a finalized result.
- Dashboard: show scoped review completion, late response, calibration and goal approval counts.
- Review detail now returns visible template sections and active competency choices needed for response submission; existing response privacy filtering remains in force.
- Frontend route and navigation are registered under Performance.

## Verification

- `cd backend && php vendor/phpunit/phpunit/phpunit --colors=never tests/Feature/Performance` — **18 tests, 332 assertions passed**.
- `cd frontend && npm.cmd test -- --run src/pages/performance/__tests__/performance.test.jsx` — **5 tests passed**.
- Frontend production build succeeded after the final response-form changes; Vite reported a JavaScript chunk above the 500 kB advisory threshold.

## Deferred

Calibration and maker-checker decisions, feedback, 1:1s, development plans, PIPs, configuration management for scales/templates/competencies, peer nomination and manager assignment, eligibility previews, cycle finalization, and broad administration/reporting screens are not exposed in this lean UI yet. Backend coverage of these functions does not mean those browser workflows are complete. Appeals/reopen, reminders, goal evidence/dependencies, recognition, mentoring and team trend analytics remain deferred per the API contract.

## Next

Re-run the frontend production build, then inspect Phase 9 Learning against the master phase spec, source, API docs and tests before implementation.

