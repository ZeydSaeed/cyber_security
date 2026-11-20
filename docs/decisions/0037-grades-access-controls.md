# ADR-0037: Grades access controls (sign-ins and mark editing)

- Status: Accepted (owner's request and approval, 2026-11-19; phase G6)
- Amends: ADR-0034 (fixed sign-in limits; one entry window for all teachers)

## Context

ADR-0034 gave the Grades module fixed rules: 5 wrong codes a minute and 20
an hour per address, and one entry window per period that opens or closes
mark entry for every teacher at once. The owner asked to set these rules
from the first month's page (`/admin`): how many attempts teachers and
students get, how long a teacher may change a mark after entering it,
read-only access, locking or unlocking a teacher's marks, stopping someone
from signing in, and sign-in counts.

## Decision

**Department-wide rules** in one row, `grade_settings`
(`GradeSettings::current()`, made with the defaults on first use):

- attempt limits per address for teachers and for students (per minute and
  per hour; defaults 5 and 20, as before);
- an optional cap on sign-ins per teacher and per student (null: none);
- the teachers' default access mode and editing hours;
- whether students may sign in at all.

**Access modes** (`Domain\TeacherAccess`): `edit`, `timed`, `view_only`,
`blocked`. They can be set for the department, for one teacher, and for
one section of a subject (`grade_subject_teachers.access_mode`). The
narrowest setting wins: section, then teacher, then department. A blocked
teacher cannot sign in; a blocked section is hidden from its teacher (404,
as a section of someone else). Editing hours are the teacher's, else the
department's.

**Editing policy** (`Domain\EditPolicy`, pure):

1. Published results are never changed by a teacher.
2. An unexpired grant on the section (`edit_until`: the owner's unlock or
   an approved request) allows every change, even with mark entry closed.
3. Otherwise mark entry must be open, and the mode decides. `timed` lets a
   mark be changed or removed only during the given hours after it was
   first entered (`grade_marks.created_at`, which the upsert keeps); removing
   a locked mark is refused too, since entering it again would restart its
   clock.

`SaveMarks` reads the policy inside its transaction; the owner's
corrections (`grades.manage`) are not limited.

**Requests** (`grade_edit_requests`): a teacher whose sheet is locked asks
from the sheet with a reason (5 to 500 characters). One pending request per
teacher, section and period (partial unique index on PostgreSQL, checked in
the action as well). The owner approves it for some hours (sets the
section's grant, keeping a longer one) or rejects it; a request is answered
once.

**Sign-ins.** Each teacher and student has `login_count` (incremented in
the database) and `last_login_at`. The owner can count a teacher, or all
students, from zero. A refused sign-in by a known person (stopped, blocked,
over the cap) gets the same answer as an unknown code, as ADR-0034 requires,
and its audit record names the person as subject (still with no actor), so
the overview can count refusals per teacher over 24 hours. Closing sign-in
for everyone is said before any code is checked ("Signing in is closed for
teachers for now"), which tells nothing about codes. Blocking a teacher
raises their lock version, ending open sessions at once; the session
middleware also checks the department's rules on every request.

**Audit.** `grades.access_settings_changed`, `grades.teacher_access_changed`,
`grades.section_access_changed`, `grades.teacher_logins_reset`,
`grades.student_logins_reset`, `grades.student_blocked` /
`grades.student_unblocked`, `grades.edit_requested` (no actor),
`grades.edit_request_decided`, each with what changed (from, to).

## Consequences

- One more query on each teacher's and student's request (the settings
  row), read once and shared through the request. `GradePeriod::for` now
  reads before inserting, so the section page stays within its budget of 8
  queries.
- A teacher's request does not notify the owner yet; the overview lists the
  pending requests (Planned).
- Per-student blocking is on `/admin/students`; per-teacher and per-section
  access is in a dialog on `/admin`.

## Reversal

The migration `2026_11_19_000000_access_controls` drops the new table and
columns. With the defaults (`edit`, no caps, 5/20), behaviour is that of
ADR-0034.
