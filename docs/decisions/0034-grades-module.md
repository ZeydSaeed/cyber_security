# ADR-0034: The Grades module (first-month marks and results)

- Status: Accepted (owner's answers, 2026-11-14 and 2026-11-15); G1, G2, G3 and G3b done, G4 next
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

## Implementation notes (G3)

- **Owner's pages** under `/grades` (users' `web` guard, second factor):
  reading needs `grades.view_results`, every change `grades.manage`, and
  each action checks again inside its transaction. The overview shows the
  entry window, the teachers' link, and each stage's subjects with each
  section's progress; a stage without its expected number of subjects and
  a subject without a teacher are flagged.
- **Teachers and subjects**: codes are set and changed, never shown again.
  The subject form lists stopped teachers too, marked, so saving a subject
  never unassigns its teacher by accident.
- **Students**: a server-side table (`StudentsTable`, ADR-0026; search by
  name with the import's spelling rules, or by number). The import file
  goes through `FileStore` (type from the bytes, audited); its id waits in
  the session between the preview and "apply". The bytes are read before
  the asynchronous scan finishes: they are only parsed as a spreadsheet
  (streaming, row limit), never served or executed.
- **Results** (`ResultSheets`, three queries per section whatever its
  size): total, percentage, subjects below the pass mark, absences,
  incomplete rows marked; checked against figures worked out by hand
  (`GradesAdminPagesTest`). Printable section and result-card pages with
  no application around them; an Excel file (openspout, right to left in
  Arabic, numbers kept as numbers), each export audited.
- **Corrections**: the owner opens a teacher's sheet (the same component)
  at any time; the change is recorded under the owner's name.
- **Bidirectional text**: names typed in either script sit in Arabic pages
  as `<bdi>` inside cells aligned with the page, not as `dir="auto"` on
  the cell, which aligned an English name to the left of an Arabic column.
- **Tests**: `GradesAdminPagesTest` (access, set-up, import, results,
  print, export, corrections, query count) and the browser test
  `grades-admin.spec.ts` (every page in Arabic and English on a phone and
  a desktop with axe; the import and a printed card).

## Owner's decisions of 2026-11-15 (G3b)

1. **Sections per stage**: the first stage has no sections; the second has
   A and B (`Stage::sections()`). The first stage's students are stored as
   section A, never shown as a section; the database refuses anything else
   (`grade_students_stage_sections_check`). An import may leave the
   section empty, or leave the column out, for the first stage.
2. **Three links that differ by one word**: `/admin` (the owner; moved
   from `/grades`), `/teacher`, `/student`. Three guards that never mix.
3. **Students see their own result** with a code the application makes:
   eight characters from an alphabet without look-alikes (XXXX-XXXX,
   about 8.5 * 10^11 codes), kept as an HMAC digest (lookup, unique) and
   encrypted with the application key (so the owner can print a group's
   slips again; each printing is audited). New students get a code at
   import; a lost code is replaced and the old one stops at once (it also
   ends the student's open session). Same sign-in limits as teachers.
4. **Publication**: students see nothing of the marks until the owner
   publishes; publishing needs entry closed, and opening entry again needs
   the results hidden (`grade_periods_published_closed_check`).
5. **Demo data** (`GradesDemoSeeder`, local only, once): five teachers,
   five subjects per stage, 12 + 10 + 10 students with codes, marks (some
   absent, a few empty), published; codes written to
   `storage/app/private/demo/credentials.txt` (not in git). Added next to
   the owner's own trial data, at the owner's choice.

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

## Amendment, 2026-11-16 (owner's request)

- A teacher's code is their **employee number**, and the owner can see it
  again. Besides the HMAC digest, the code is now kept encrypted with the
  application key (`grade_teachers.code_encrypted`), as students' codes
  are. It is sent to the owner's page only on request (a partial reload of
  `codes`), and each showing is audited (`grades.teacher_codes_viewed`).
  Teachers saved earlier have no copy until their number is set again.
- The owner can delete a teacher, a subject or a student, and change a
  subject's stage and a student's stage, section and number. Records with
  marks are protected: a teacher who entered marks can only be stopped; a
  subject or student with marks keeps its stage and cannot be deleted.
- Students' codes are shown in the owner's student list to those with
  `grades.manage` (each showing audited as `grades.student_codes_listed`)
  and on the owner's result card as the student's ID. The owner may type a
  student's code (8 to 16 Latin letters and digits, unique); a new student
  still gets one made by the application. The teachers' page shows the
  employee numbers directly (each visit audited).
- Stages are shown by name ("First", "Second"), never as a number.

## Amendment, 2026-11-20 (owner's decisions after the full-size audit)

See `docs/evidence/grades-audit/2026-11-20-full-size-audit.md`.

- **The student import is removed.** No page had reached it since the
  import card left the students page (2026-11-16). Gone: the upload,
  template, preview, apply and cancel routes, `ImportStudents`, the
  `Application/Import` classes, the preview page, `Stage::fromText`,
  `Section::fromText` and `grades.import_max_rows`. The sections above
  that describe the import are history. Students are added one at a
  time (`SaveStudent`); the seeders use it too. The race test now has two
  owners add the same student at once: one student, one refusal.
  `openspout` stays, for the Excel export of results. Old
  `grades.students_imported` audit records keep their label.
- **The student number is entered on the student's form** (optional, at
  most 40 characters, unique, audited as a change); it was set only by the
  import before.
- **The printed result card shows the student number, never the sign-in
  code.** This replaces "on the owner's result card as the student's ID"
  above. A card posted or passed around must not open the student's
  result page. Codes are printed only on the code slips.
- **Publishing asks first.** Publishing all results, or one section, opens
  a confirmation that says how many marks are still missing (in how many
  sections) and how many subject sections their teacher has not approved,
  or that everything is entered and approved. Hiding does not ask.
- **A section's type is two boxes, theoretical and practical.** Either or
  both may be ticked; none is "not stated". `TeachingKind` gains `both`
  ("Theoretical and practical"); PostgreSQL's check accepts it
  (migration `2026_11_20_100000_teaching_kind_both`). The print slip ticks
  both boxes for such a section.
- **Publishing chooses its stages and sections, then shows what is
  missing.** The window tab ticks stages and sections (several, or all;
  a published section shows "Published" with Hide). A button lists, for
  what is ticked, each subject section with no teacher, with marks still
  missing, or not approved, with its subject, teacher and section.
  Publishing opens a dialog with that list; with shortages its button is
  "Publish anyway", and Cancel changes nothing. Several sections publish
  together in one transaction (`PUT /admin/publish/sections`,
  `PublishResults::sections`); all of them publishes the whole period.
- **"Settings" is called "Control panel"; academic reports at
  /admin/reports.** The sidebar's last item opens the academic reports
  (`ReportsController`, `AcademicReports`, `ReportAdvice`): results by
  section and stage (passed, failed, exempt, incomplete, rates, means),
  the top ten of each stage, subjects compared within their stage,
  teachers' progress and sign-ins, students' sign-ins and views, appeals
  with their teachers and answers, the marks' dates and corrections, and
  recommendations by fixed rules. Read with grades.view_results; opening
  it is audited (`grades.reports_viewed`). The platform's old redirect of
  /admin/reports is gone. Checked two ways: hand-worked data
  (`ReportsCheckSeeder`, `AcademicReportsTest`) and an independent recount
  (`php artisan grades:reports-check`).
- **Students' appeals have a tab of their own; the control panel's cards
  follow the owner's picture (2026-11-20).** Each card is a soft tile with
  a faint border, a solid round badge, its label, a large number and a
  line of state; a ninth tile colour (`tile-9`, `palette.json`) serves the
  appeals. The appeals tab (`/admin?tab=appeals`, also linked from the
  students' tab) shows the appeals by status in the picture's layout (a
  bar by share, a legend, a card per status, and who answered), then
  their settings, saved on their own (`PUT /admin/results/appeals`,
  `ManageResults::appeals`, audited `grades.appeal_settings_changed`):
  appeals on or off; who answers, the subject's teacher (on their sheet,
  the department told and still able to answer) or the administration
  alone; open from a date (`appeal_from`, migration
  `2026_11_20_200000_appeal_opens_from`; empty: at publication), closed at
  a date, and/or kept open for days, hours or minutes counted from when
  they open (the earlier close applies); and who may appeal. "What the
  student sees" no longer carries the appeals or the appeal box.
- **The reports' figures as status boards** (owner's picture, 2026-11-20):
  a bar split into the states' shares, a legend with each share in
  words, and a soft card per state with a round icon, its name and its
  number (`StatusBoard`). Its colours are the palette roles
  `status-good`, `-wait`, `-bad`, `-info` and `-final` (each with
  `-foreground` and `-soft`), chosen with the colour-blindness validator
  for the bar's orders (worst adjacent ΔE 12.7 light, 16.4 dark) and
  contrast-tested in pairs.json. Dark mode lets the amber go above the
  validator's lightness band (0.83) to keep 3:1 on the dark card.

## Amendment, 2026-11-21 (full data-flow audit, owner's request of 2026-11-20)

See `docs/evidence/grades-flow/2026-11-21-flow-check.md`.

- **`php artisan grades:flow-check`** fills the department as it stands
  (every student gets a mark in every subject of their stage, entered by
  the section's own teacher; approvals, edit requests, behaviour and
  notes), then sets every value of every setting through the module's own
  actions and compares each outcome with a rule worked out in the command
  independently: pass and exemption percentages and grade bands (every
  result recounted from the raw tables), what the student sees (each
  option alone, all, none), what teachers see and when (off, once
  published, always; each option; nothing, one section or all
  published), the department's, a teacher's and a section's access with
  mark entry, publication, approval, grant and editing time (3,600
  combinations, each also tried as a real save), students' sign-in,
  300 random moves of mark entry and publication, appeals (on/off, who
  may, from, until, for how long, which section is published, at times
  before, inside and after the window) and who answers them. The control
  panel's figures and `grades:reports-check` are recounted after each
  stage. Everything runs in one transaction and is rolled back; `--keep`
  commits the fill and a final state (published, appeals open for seven
  days, some answered by teachers, some by the department, some
  waiting), never in production and only when every check passed. Users,
  roles, teachers, subjects, assignments and students are hashed before
  and after and must be unchanged. `FlowCheckCommandTest` runs it on a
  small department.
- **Defects found and fixed** (pinned in `FlowAuditFindingsTest`):
  1. A teacher could answer an appeal of a section blocked for them
     (`PUT /teacher/appeals/{uuid}` checked only that they teach it).
     `ManageAppeals::answerAsTeacher` now refuses a blocked section or a
     stopped teacher, as the sheets do.
  2. Without "other subjects", a teacher's results page still sent the
     figures that count every subject (absences, missing marks,
     complete, passed, the absence flag, the section summary).
     `ResultSheets::forTeacher` counts them over the subjects shown.
  3. Publishing everything after a section had been published on its
     own restarted that section's appeal time. `publishedAtFor` keeps the
     earlier of the two moments.
  4. A teacher or a section could be put in the limited-time mode with no
     editing time anywhere, which then edited without a limit; and the
     department's time could be cleared while such a teacher or section
     relied on it. `ManageAccess` refuses all three.
  5. Appeals could be switched on with neither a time nor a closing
     date: the page said students may appeal, yet they never could.
     `ManageResults::appeals` refuses it; the hint says so.
  6. "Hide the results" (`PUT /admin/publish`, published false) did
     nothing when only single sections were published, against its own
     rule that hiding all hides them too. It now hides them.
- **Left as is, for the owner to decide**: a department editing time
  saved under "edit" or "limited time" is kept while the mode is
  read-only or no access (by design, ManageAccess) and then still
  applies to a teacher or section given "edit" individually, although
  the page greys the field out in that mode with the hint that it is used
  only with those two modes. The "out of" option hides the
  maximum on the card only; the maximum is still sent (it is not
  sensitive, and each subject's percentage implies it).
