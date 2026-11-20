# ADR-0041: Approving marks, their history, and subject figures

- Status: Accepted (owner's approval of the suggestions, 2026-11-19; phase G10)
- Builds on: ADR-0037 (editing policy), ADR-0039 (results)

## Context

Among the suggestions the owner approved are four that this phase covers:

1. a history of every mark, shown to the owner;
2. a teacher's "approve the marks", after which they lock;
3. a progress view;
4. each subject's figures (pass rate, highest, lowest, mean).

## Decision

**Approval** (`grade_section_approvals`, one per subject, section and
period; `Application\Actions\ApproveSection`):

- A teacher approves a section only while they could still change it, and
  only once every student has a mark.
- Approved marks are read-only for the teacher (`EditPolicy::$approved`),
  unless a grant is in force (an approved edit request or the owner's
  unlock).
- A change the teacher makes under a grant clears the approval
  (`grades.section_approval_cleared`), so the teacher must approve again.
- The owner's corrections keep the approval.
- The owner withdraws an approval from the section's sheet
  (`grades.section_approval_withdrawn`).
- An approval is audited with the teacher named and no actor.

**History** (`Application\Queries\MarkHistory`):

- Read from the `grades.marks_saved` audit records that `SaveMarks` already
  writes, filtered to the subject, section and period. Nothing is stored
  twice.
- Each change shows when, who (the teacher's name, or the user who
  corrected), the student, and the mark before and after.
- The newest 300 records are shown on the owner's sheet page.

**Figures** (`ResultSheets`, `stats`): for each subject in the section,
the results page shows:

- marks entered and absences;
- marks passed and the pass rate;
- the highest, lowest and mean of the marks present.

A teacher's view receives the figures of the subjects it shows. A
student's card receives none.

**Progress view.** The overview already shows marks entered against
students for each subject and section, with each section's teacher. An
"Approved" badge now marks the sections whose teacher approved them.

## Consequences

- The history depends on audit records, which are append-only and chained
  (ADR on audit). Their retention sets how far back it goes.
- One more query on a teacher's section page (the approval, read once for
  the policy and the page); the page stays within its budget of 8.

## Reversal

Roll back `2026_11_19_400000_section_approvals`. The history and the
figures need no tables.
