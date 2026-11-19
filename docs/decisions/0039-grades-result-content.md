# ADR-0039: What a result says and shows

- Status: Accepted (owner's request and approval, 2026-11-19; phase G8)
- Amends: ADR-0034 (every subject out of 100, pass mark 50 from config,
  "no ranking")

## Context

The owner asked that a result be able to carry the total, the average, the
student's rank, the teachers of each subject, the maximum of each subject
(80, 100 or any other), the student's status, the number of failed
subjects, a grade for each mark and an overall grade, individual and
general exemption with their own marks and colours, colours for failing
and average marks, the subject type, behaviour and notes. Each part should
be shown or hidden by the owner.

On 2026-11-19 the owner reversed the decision of 2026-11-14 ("no
ranking"): the rank is within the **section**.

## Decision

**Subject scale** (`grade_subjects`):

- `max_mark`: 1 to 1000, default 100.
- `pass_mark` and `exempt_mark`: optional. When null, each is the
  department's percentage of the maximum, rounded up
  (`Domain\SubjectScale::from`).
- The maximum cannot be set below a mark already entered.
- `SaveMarksRequest` checks every mark against the route subject's maximum,
  and names the student. `SaveMarks` checks it again under the subject's
  lock.
- The table's CHECK now allows 0 to 1000.

**Department settings** (`grade_settings`):

- `pass_percent`: default 50. It replaces `config('grades.pass_mark')` and
  `GRADES_PASS_MARK`.
- `exempt_subject_percent` and `exempt_overall_percent`: optional, and never
  below the pass percentage.
- `grade_bands`: the lowest percentage of each band, strictly falling.
  Defaults: excellent 90, very good 80, good 70, fair 60, pass 50.
- `result_options`: what a result card shows (`Domain\ResultDisplay`).
- `mark_tones`: the tone of failed or absent, fair, exempt and excellent
  marks.

**Calculation** (`Domain\ResultCalculator`, pure):

- Total and maximum add up each subject's own maximum.
- For each mark: its percentage, whether it passed, its band, and whether
  it is exempt.
- Once all marks are in, an overall band is given. Any failed subject makes
  the overall band Fail.
- General exemption needs every subject passed and a percentage at or above
  the general percentage.

**Rank** (`ResultCalculator::ranks`):

- Within the section, by percentage, among complete results only.
- Equal percentages share a rank and the next rank is skipped (1, 1, 3).
  The owner's question on ties went unanswered; this is the usual
  convention, and it can be changed.

**Behaviour and notes** (`grade_student_notes`):

- One row per student and period: a behaviour from a fixed scale and free
  notes (at most 1000 characters).
- The owner writes them from the results page. Each change is audited.

**What students receive.** `ResultSheets::forStudent` sets every hidden
part to null before the page is rendered, so a hidden rank or note never
reaches the student's browser. The owner's results page, printout and Excel
file always show everything. The owner's printable card follows the same
options as the student's card.

**Colours.** A tone is one of `none`, `success`, `warning`, `info`,
`danger`, mapped to the design system's status `-text` tokens. It is never
a colour value, and a coloured mark always has words beside it ("Below the
pass mark", "Exempt", the grade).

## Consequences

- A result page now runs 6 queries (settings, subjects, teaching rows,
  marks, notes, students), whatever the section's size.
- A student's card computes the whole section, to find the rank (a few
  hundred students at most).
- The Excel file gains columns: each subject's maximum, rank, overall
  grade, exemption, behaviour and notes.

## Reversal

Roll back `2026_11_19_200000_result_content`. Its `down()` restores the
0–100 mark constraint, which fails if marks above 100 exist.
