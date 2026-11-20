# ADR-0045: Appeal reminders, the teacher's to-do, and who saw a result

- Status: Accepted (owner's approval of the suggestions, 2026-11-19; phase G13, third round)
- Builds on: ADR-0042 (alerts), ADR-0044 (appeals answered by teachers)

## Context

After ADR-0044 three suggestions were offered, and the owner asked for
all of them:

1. remind the department before appeals close, and of appeals left
   unanswered;
2. give each teacher a short to-do;
3. record who saw a student's result.

## Decision

**Appeal reminders** (`Application\AppealReminders`, command
`grades:appeal-reminders`, scheduled hourly by the module's provider):

- **Closing soon.** A published section's appeals close within a day:
  the department is told when, and how many of the section's appeals
  still wait.
    - Sent once per section and closing moment.
    - Not sent for sections without students.
- **Overdue.** Appeals waiting 48 hours or more: the department is told
  how many, once a day.
- **Delivery.** Both go through the outbox and the existing
  `NotifyGradesManagers` job to everyone with `grades.manage`
  (`grades_appeals_closing`, `grades_appeals_overdue`).
- **Sent once.** A cache key, added in the same transaction as the
  outbox message, keeps each reminder to one sending.
- `ManageAppeals::closesAt` computes the closing moment, for the student
  page and the reminders alike.

**The teacher's to-do** (`components/teacher-summary.tsx`, on the
teacher's subjects page). It lists, per subject and section:

- marks still changeable that lock within a day (`locking_soon`, read
  only while mark entry is open);
- appeals waiting (ADR-0044);
- students without a mark, while the teacher can still enter them.

It shows nothing when there is nothing to do. Teachers are not users, so
the to-do is on their page and is not a notification.

**Who saw a result** (`Application\ResultViews`, in the audit trail):

- **The student** reading their published result:
  `grades.student_result_viewed`, with no actor.
- **A user** opening the student's card: `grades.student_card_viewed`.
- **The section's teachers:** their results page already records
  `grades.teacher_results_viewed` per section (ADR-0040). Those records
  are counted for every student of that section.
- **No flooding:** a view repeated within 10 minutes by the same person
  is not recorded again.
- **Where it shows:** the owner's card lists the newest 100 views, below
  the card, never printed.

## Consequences

- An hourly command more on the scheduler.
- Two audit records more per visit at most, on the student's page and
  the owner's card.

## Reversal

Remove the schedule entry. Without it, the views and the to-do still
work on their own.
