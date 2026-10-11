# Learning API (Phase 9 lean core)

Authenticated and tenant-scoped under `/api/v1/learning`; all routes require the `learning` module. Tenant ownership comes from the server. Course drafts are only returned to catalog managers; employees see published catalog entries.

## Catalog and paths

- `GET courses?search=&delivery=&per_page=` — paginated course summaries. `GET courses/{uuid}` — course detail including prerequisites, granted skills and versions.
- `POST courses` / `PATCH courses/{uuid}` — catalog manager fields: code, title, description, delivery (`internal|external|online|classroom`), provider, duration, cost, currency, capacity, approval flag, pass/attendance thresholds, certification code, skill grants and prerequisite UUIDs.
- `POST courses/{uuid}/status {status: published|retired}` — publish or retire.
- `GET paths` — published paths with current employee progress; managers also see drafts. `POST paths {name,description?,items:[{course_uuid,required?}]}` — create a path.
- `GET courses/{uuid}/sessions`; `POST courses/{uuid}/sessions {code,starts_on,ends_on,location?,instructor?,capacity?,waitlist_enabled?}` — list or schedule sessions.

## Employee learning and skills

- `POST courses/{uuid}/enrol {session_uuid?}` — self-enrol. The API rejects duplicate active/completed enrolments and unmet prerequisites. Approval-required courses enter `pending_approval`; a full session enters the waitlist when enabled.
- `GET my` — current employee's enrolments and course summaries.
- `GET skills`, `GET skill-levels`, `GET my/skills` — active skill list, proficiency scale, self profile/history and current-role gap analysis.
- `POST my/skills {skill_uuid,level,note?}` — self-declare proficiency. Self declarations remain unverified; managers validate through the domain service when that workflow is exposed.

## Access

Routes apply the `learning.*` permissions and tenant scope. Self-service endpoints use the employee record linked to the signed-in person; cross-tenant course and session IDs resolve as not found. Course creation, editing, publication, session scheduling and path creation require `learning.catalog.manage`.

## Deferred from the current API surface

Enrollment decisions/manager assignments, attendance/completion, assessment attempts, certificates/renewals, compliance assignments/reminders, skill taxonomy and role requirement administration, manager validation/evidence endpoints, budgets/training requests, feedback and development-plan integration are not yet routed. These are described in the master phase spec and must not be treated as implemented merely because supporting tables or partial domain services exist.
