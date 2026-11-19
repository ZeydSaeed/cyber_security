# ADR-0043: Editing time, a mark's journey, and who may appeal

- Status: Accepted (owner's request, 2026-11-19; phase G13)
- Amends: ADR-0037 (editing hours), ADR-0039 (result card options),
  ADR-0041 (history shown to the owner only), ADR-0042 (appeal days)

## Context

The owner asked, on the page at `/admin`:

1. the sidebar item "First-month marks" to become "Settings";
2. the time a mark stays changeable to be used only with "Enter and
   change" and "Change for a limited time", and to be given in minutes,
   hours and a date rather than hours alone;
3. teachers to see each mark's journey: from its first entry to its last
   change, with when and who;
4. the student's card to offer the absences, the average, total, rank,
   grades, behaviour and notes, the appeal box, how long appeals stay
   open, and appeals for every student or for one student.

Items 4's average, total, rank, grades, behaviour and notes already
existed (ADR-0039); they are now grouped and ordered under "What the
student sees in the result".

## Decision

**Sidebar.** The overview's item and page title are "Settings" (icon
`Settings`). The address stays `/admin`.

**Editing time** (`grade_settings.teacher_edit_minutes`,
`teacher_edit_until`; `grade_teachers.edit_minutes`, `edit_until`):

- A duration in minutes (the page shows days, hours and minutes; the
  server adds them, `Http\DurationInput`), counted from a mark's first
  entry, at most 365 days.
- And/or a deadline: every mark already entered locks at that moment.
  Whichever comes first applies (`EditPolicy::editableUntil`).
- Both apply in `edit` and `timed` (`EditPolicy::isLimited`). `edit` may
  have no limit; `timed` needs a duration or a deadline. In `view_only`
  and `blocked` the fields are disabled and the saved values are kept.
- A teacher's own limit (either field set) replaces the department's as a
  whole.
- New marks can always be entered while the sheet is writable.
- The date is entered in the reader's local time and sent as ISO 8601
  with its offset; the server stores UTC.
- Grants (a section's unlock, an approved request) stay in hours.

**A mark's journey.** `MarkHistory` rows carry `student_uuid`. The
teacher's sheet offers "Show the marks' history", which loads the
history as an Inertia optional prop (`only: ['history']`), so the sheet
keeps its budget of 8 queries. The history component, on the owner's and
the teacher's sheets, can show one student's journey oldest first, each
step marked "First entry", "Changed" or "Removed", with who and when.
Changes by the department show the user's name and "Department".

**The student's card** (`ResultDisplay::OPTIONS`):

- `absences` (default off): the number of subjects the student was absent
  from. Hidden ones are sent as null.
- `appeals` (default on): the appeal box. Unticked, appeals are closed.
- `frequent_absence` is now removed from the student's card. ADR-0042
  said it never reached the student; it did. Fixed here.

**Appeals** (`grade_settings.appeal_minutes`, `appeal_until`,
`appeal_scope`; `grade_students.appeal_allowed`):

- Open from the section's publication for the given minutes (at most 90
  days) and/or until a date, whichever comes first. Neither: closed.
- `appeal_scope`: `all`, or `selected`. With `selected`, only students
  the owner allowed on `/admin/students` ("Allow an appeal") may appeal.
  Audited: `grades.student_appeal_allowed` / `_disallowed`.

## Migration

`2026_11_19_700000_edit_time_and_appeal_reach` converts hours to minutes
(× 60) and days to minutes (× 1440). Where `edit` was chosen explicitly,
the stored limit is cleared, so those teachers stay unlimited as before.
`down()` rounds back up to hours and days.

## Consequences

- One more query on `/admin/students` (the settings row).
- The history is read only when a teacher asks for it.

## Reversal

Roll back the migration. Hiding the new options and leaving the scope at
`all` gives the behaviour of ADR-0042.
