# ADR-0044: What the student sees, teachers answering appeals, a student's journey

- Status: Accepted (owner's request, 2026-11-19; phase G13, second round)
- Builds on: ADR-0042 (appeals), ADR-0043 (appeal reach, mark history)

## Context

The owner asked for "What the student sees in the result" and the appeal
settings to be in the "Students" box of the settings page (`/admin`). They
also wanted:

- the student's absences on the card;
- each mark's journey available to the owner from a student's card;
- other suggestions to help run the department, such as telling the
  owner and the teacher when an appeal comes in, and letting the owner
  allow teachers to answer appeals.

## Decision

**The Students box.** The parts of the result card, the appeal box, how
long appeals stay open, who may appeal, and whether teachers answer
appeals are saved from the Students box. They go to
`PUT /admin/results/student-view`
(`ManageResults::studentView`, audited `grades.student_view_changed`).

- The box is inside the access settings form, and forms cannot nest. Its
  fields therefore join an empty form, rendered after the access form,
  through the HTML `form` attribute. Its button saves them, and only
  them.
- The result rules form (pass mark, bands, tones, absence flag) no longer
  sends the card's parts or the appeals: it keeps the saved ones.

**Teachers answering appeals** (`grade_settings.teacher_appeal_replies`,
default off; `grade_appeals.decided_by_teacher_id`):

- When the owner allows it, the subject's teacher of the student's
  section may accept or reject a waiting appeal once, with an answer,
  from their sheet (`PUT /teacher/appeals/{appeal}`,
  `ManageAppeals::answerAsTeacher`).
- The answer is audited with no actor and the teacher named.
- The department is notified (`grades_appeal_answered`, through the
  existing outbox job).
- A teacher of another section is refused. Accepting changes no mark, as
  before.

**Teachers' alert.** The teacher's subjects data carries each section's
number of waiting appeals. It is read only once something is published.

**The owner's page of one student** (`/admin/results/students/{uuid}`),
below the card and never printed:

- each subject's mark journey, from first entry to last change, with
  when and who (`MarkHistory::forStudent`, which reads only the audit
  records that name the student);
- the student's appeals, and who answered each;
- when appeals are for chosen students, a button to allow or withdraw
  this student's appeal.

## Consequences

- The appeal list on `/admin` still shows only waiting appeals; an
  appeal a teacher answered leaves it, and the department is notified.
- Teachers are not users, so their alert is on their own pages, not a
  notification.

## Reversal

Roll back `2026_11_19_800000_teacher_appeal_replies`, and move the
fields back into the result settings form.
