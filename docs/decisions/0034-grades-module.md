# ADR-0034: The Grades module (first-month marks and results)

- Status: Accepted (owner's answers, 2026-11-14); G1 and G2 done, G3 next
- Date: 2026-11-14

## Context

The Cyber Security department needs the first-month marks of its students
collected from the teachers and turned into one result per student.

- Two stages (first year, second year), each with two sections (A, B).
- Each stage has its own 8 subjects (16 in all); each subject has one
  teacher; a teacher may teach more than one subject.
- One mark per student per subject: an integer from 0 to 100, or absent.
- The owner (administrator) imports the students from an Excel file,
  creates the teachers and sets each teacher's identification code.
- The owner sends the teachers one link. A teacher opens it, types their
  code, and sees only their own subjects and those subjects' students.
- The application builds each student's result from the 8 marks.

The template's `Requests` module (ADR-0033) is the model to copy; it is not
part of this application's business.

## Problem

Teachers must enter marks without a full user account (password plus a
second factor, ADR-0015), while marks stay attributable, limited to the
teacher's own subjects, and safe from guessing.

## Options

1. Teachers as users with a `teacher` role: full accounts, invitation
   e-mail, second factor. Strongest, but heavier than the owner asked for.
2. A secret link per teacher, no code: simple; anyone holding the link is
   the teacher, and links leak through chat history.
3. One shared link plus a per-teacher code set by the owner, on a
   separate `teacher` guard limited to grade entry. Chosen by the owner.

## Decision

Option 3, as a business module `app/Modules/Grades` built like
`app/Modules/Requests`.

**Domain** (no Eloquent)

- `Stage` (1, 2) and `Section` (A, B) enums.
- `Period` enum, `month_1` only for now (later months add a case).
- `EntryWindow` state machine per period: `open → closed`, and the owner
  may reopen (`closed → open`). Teachers write only while it is open.
- `Mark` value object: integer 0–100, or absent. Pass mark 50
  (configurable).
- `ResultCalculator`: total (out of 800), percentage, subjects below the
  pass mark, complete or not (all 8 entered). An absent subject counts as
  0 in the total and as below the pass mark, and is shown as absent. No
  ranking.

**Data** (PostgreSQL, ADR-0012 conventions, bigint id + UUIDv7 in URLs)

- `grade_teachers`: name, `code_digest` (HMAC-SHA256 of the code with a
  dedicated `GRADES_CODE_KEY`, unique; the code itself is never stored),
  `is_active`, `last_seen_at`.
- `grade_subjects`: stage, name, `teacher_id` (RESTRICT), sort order;
  unique (stage, name); unique (id, stage) for the composite key below.
- `grade_students`: full name, stage, section, optional `student_number`
  (unique when present), unique (stage, section, normalized name);
  unique (id, stage).
- `grade_periods`: period, status (`open`/`closed`), opened/closed times.
- `grade_marks`: student, subject, period, `stage`, `mark`, `is_absent`
  (CHECK: absent with no mark, or present with a mark from 0 to 100),
  `entered_by_teacher_id`, `lock_version`; unique (student, subject,
  period). Composite foreign keys (student_id, stage) and (subject_id,
  stage) make a mark for a subject of the other stage impossible in the
  database, not only in code.
- CHECK constraints for every enum column; an index per read path
  (section sheet, teacher's subject list, results by section) and per
  foreign key (`PostgresConventionsTest`).

**Student import** (owner)

- Upload `.xlsx` (or `.csv`) through `FileStore` (ADR-0020; both types
  are already on the allow-list). Columns: name, stage, section, optional
  student number.
- Two steps: a preview listing new rows, updated rows, and rejected rows
  with the reason (unknown stage or section, empty name, duplicate in the
  file); then "apply" in one transaction. Repeating an import changes
  nothing.
- `.xlsx` is read with `openspout/openspout` (MIT, pure PHP, streaming),
  approved by the owner; `.csv` is accepted too.

**Teacher access**

- A `teacher` guard (session driver, `grade_teachers` provider) beside
  `web`; a teacher session never reaches the owner's pages, and the
  owner's session never counts as a teacher.
- `/teacher`: one form, one field (the code). Lookup by HMAC digest.
  The same answer for every failure. Rate limits: 5 attempts a minute and
  20 an hour per IP, then a wait. Every success and failure is audited
  (`grades.teacher_login`, `grades.teacher_login_failed`, the code never
  in the record).
- Codes are at least 8 characters; the owner sets and can reset them;
  two teachers cannot share one. Deactivating a teacher ends their
  sessions.
- Session: 30 minutes idle, regenerated at login, ended at logout.

**Mark entry** (teacher, phone first)

- The teacher's subjects; for each, sections A and B of that stage with
  the student list, a number field and an "absent" choice per student. Save per section in
  one transaction (`lockForUpdate`, `lock_version`, a second click
  changes nothing).
- Refused when the window is closed, the subject is not the teacher's, or
  a mark is outside 0–100 — each with a precise message.
- Each save audited as `grades.marks_saved` with old → new per student.

**Owner pages** (existing administrator, second factor kept)

- Teachers (create, set or reset code, deactivate), subjects per stage
  (assign teacher), students (import, list, edit), the entry window
  (open, close, reopen).
- Progress: subject × section matrix of entered / total.
- Results: per section, students × 8 subjects, total, percentage,
  subjects below 50; incomplete results marked as such. A printable
  Arabic result card per student and per section (print stylesheet), and
  an Excel export of the section sheet (needs the same dependency).
- New permissions `grades.manage` and `grades.view_results`, granted to
  `administrator` and written into `PermissionMatrixTest`.

**Delivery in phases**, each ending with the Arabic report:

| Phase | Scope                                   | Acceptance                                                                                                                                                                                                                                                                            |
| ----- | --------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| G1    | Remove `Requests`; domain, data, import | `Requests` gone; every platform test that used it rewritten on Grades (page-level tests follow with the pages, see the implementation notes); constraint tests write bad rows and fail; import preview/apply tested incl. repeat; unit tests for `ResultCalculator` and `EntryWindow` |
| G2    | Teacher guard and mark entry            | Login/limits/lockout tests; a teacher cannot read or write another subject (403/404); closed window refuses; concurrency test of two saves; usable at 360 px                                                                                                                          |
| G3    | Owner pages, results, print, export     | Results (incl. absent) match hand-computed fixtures; print in Arabic RTL; translations complete; dashboard widget through a `Contracts` query                                                                                                                                         |
| G4    | Verification                            | `composer test`, `test:pgsql`, build, signed-in ZAP on `/teacher`, Arabic screenshots desktop/phone                                                                                                                                                                                   |

## Implementation notes (G1)

- **Teachers in the audit trail.** `audit_events.actor_id` means a user
  (the activity timeline reads names from `users`), so a teacher's change
  is recorded with no actor id and the teacher's UUID and name in its
  metadata. The audit chain itself is unchanged.
- **Concurrency.** `SaveMarks` locks the subject for update and the period
  for share; `ChangeEntryWindow` locks the period for update. A save
  either commits before a close or is refused after it (tested with real
  parallel processes). Student imports take a table lock, so two at once
  never create a student twice.
- **Settings** are in `config/grades.php` (in `config/`, so `env()` still
  works with a cached configuration): pass mark, subjects per stage, the
  code key, the largest import.
- **Platform tests that used Requests**, rewritten on Grades in G1:
  concurrency (`RaceConditionsTest`, `worker.php`), metrics
  (`ModuleMetricsTest`), the restore-drill data (`DrillSeeder`), the
  dashboard widget and its tests, the permission matrix, the alert rule
  tests and the browser seed data. Tests of the Requests _pages_ (its
  HTTP tests, browser scenario, large-table budget and load script) have
  no Grades counterpart until the Grades pages exist; they are written in
  G2 (teacher pages, load scenario) and G3 (owner pages, large table).

## Implementation notes (G2)

- **The teacher guard** is registered by the module's provider (`teacher`,
  session driver, `grade_teachers`). The users' pages use the `web` guard
  and never accept a teacher; the teachers' routes use `TeacherSession`
  and never the users' `auth`. A user and a teacher may be signed in on one
  browser; signing one out leaves the other.
- **Audit.** The platform's `auth.login`/`auth.logout` records are now for
  the users' guard only, and `AuditLogger::record(..., withoutActor: true)`
  records an act with no actor even when a user is signed in on the same
  browser. Teacher sign-ins, failures, lockouts, sign-outs and saves use it.
- **Sessions** end when the teacher is stopped, when the code changes (the
  lock version is kept in the session) and after 30 idle minutes.
- **Limits.** Failed codes: 5 a minute and 20 an hour per address, counting
  failures only. Saving: 60 a minute per teacher, not per address, since a
  school's teachers may share one.
- **Pages** (`resources/js/modules/grades`): the code page (users' sign-in
  layout), the subjects with each section's progress, and the section's
  sheet (Enter moves to the next student; "absent" empties and locks the
  field; wrong values named per student, in the browser and by the server).
  Another teacher's subject is a 404.
- **Tests**: `TeacherAccessTest`, `TeacherPagesTest` (incl. a fixed query
  count per sheet), `tests/browser/teacher.spec.ts` (Arabic and English at
  360 px, axe, no sideways scrolling), and the load scenario
  `tests/load/teachers.js` with `scripts/perf/run-teachers.ps1`.

## Consequences

- A teacher's code is a shared secret: whoever has it can enter that
  teacher's marks until the owner resets it. The audit trail shows every
  change, and closing the window stops all entry.
- The `Requests` module is unrelated to this application and is removed
  in G1 (owner's decision). It is the only user of several platform
  features (outbox, metrics source, drill seeder, concurrency tests), so
  each of its platform tests is rewritten on Grades in the same phase
  rather than deleted.

## Security implications

Guessing is limited by code length, the HMAC lookup and the rate limits;
the code is never stored or logged. The teacher guard reaches only the
mark-entry routes. Composite keys stop cross-stage marks in the database.

## Performance implications

At most a few hundred students and 16 subjects: every page is a handful of
indexed queries; results are computed on read, no cache needed.

## Reversal or migration strategy

The module is one provider line in `bootstrap/providers.php` and its
folder; its tables have their own migrations and can be dropped together.
Later months add a `Period` case, not new tables.

## Owner's answers (2026-11-14)

1. Hosting: postponed. The application runs locally until a separate
   deployment decision.
2. Absent: yes.
3. Pass mark 50 per subject; no ranking.
4. `openspout/openspout`: approved.
5. Remove `Requests`: yes (in G1).
6. Subject and teacher names: entered later by the owner in the
   application.
7. Colours: `#EEE2DE`, `#EA906C`, `#B31312`, `#2B2A4C`, applied as the
   design tokens (light: navy primary, warm surfaces, crimson for
   destructive and focus; dark: navy surfaces, peach primary), every pair
   checked by `DesignTokensTest`.
