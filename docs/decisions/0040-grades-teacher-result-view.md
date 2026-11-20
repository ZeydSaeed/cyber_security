# ADR-0040: Teachers read their own students' results

- Status: Accepted (owner's request and approval, 2026-11-19; phase G9)
- Builds on: ADR-0037 (section access), ADR-0039 (result content)

## Context

The owner asked that teachers be able to read the results of their own
students only. The owner chooses what they see: behaviour, rank, grades,
notes, the average and the like.

## Decision

**When.** `grade_settings.teacher_results` (`Domain\TeacherResults`) takes
one of three values:

| Value           | Teachers see results                             |
| --------------- | ------------------------------------------------ |
| `off` (default) | Never                                            |
| `published`     | Once the owner publishes the results to students |
| `always`        | Also while marks are still coming in             |

**Who.** A teacher sees only the sections they teach, through the
`grade_subject_teachers` rows that name them. A section blocked for them
(ADR-0037) is not theirs. Asking for another section is a 404, as on the
mark sheets.

**What.** `grade_settings.teacher_view` holds the options in
`Domain\ResultDisplay::TEACHER_OPTIONS`:

- `other_subjects`
- `total`
- `percentage`
- `rank`
- `failed_count`
- `mark_grade`
- `overall_grade`
- `exemption`
- `student_status`
- `behaviour`
- `notes`

By default a teacher sees the total, the percentage and the count of
failed subjects. A teacher always sees the marks of their own subjects.
Other subjects' marks are shown only with `other_subjects`.

`ResultSheets::forTeacher` builds the section's sheet, then drops the
hidden subjects' columns and sets every hidden field to null. A hidden
part never reaches the teacher's browser.

The total and percentage cover all subjects, even when the other subjects'
marks are hidden. That is the student's real result, and the owner chooses
whether to show it.

**Audit.**

- `grades.teacher_results_viewed`: each reading, with stage and section and
  no actor. A teacher is not a user.
- `grades.teacher_view_changed`: each change of the settings.

**Where.**

- The teacher reaches `/teacher/results` from "Your students' results" on
  their subjects page.
- The owner sets both values under "The result card" on `/admin`.

## Consequences

Each reading computes the section's full result, about 6 queries, then
cuts it down.

## Reversal

Set `teacher_results` to `off`, or roll back
`2026_11_19_300000_teacher_result_view`.
