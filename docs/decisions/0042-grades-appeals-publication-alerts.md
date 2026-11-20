# ADR-0042: Appeals, per-section publication, alerts and the absence flag

- Status: Accepted (owner's approval of the suggestions, 2026-11-19; phase G11)
- Builds on: ADR-0034 (publication), ADR-0037 (requests), ADR-0039 (results)

## Context

The rest of the suggestions the owner approved:

- alerts when an edit request arrives or marks are about to lock;
- students appealing a mark within a period the owner sets;
- a "frequently absent" flag;
- publishing results to one section at a time.

## Decision

**Per-section publication** (`grade_section_publications`,
`PublishResults::section`).

- `GradePeriod::$results_published_at` still means _every_ section.
- Besides it, single sections can be published while the period is not
  published to all. `GradePeriod::publishedFor(stage, section)` is what
  students, teachers' policies and teachers' result views consult.
- Publishing a section needs mark entry closed, as before.
- Opening entry again needs every section hidden (`anyPublished`).
- Hiding all hides the single sections too.
- While entry is open no section can be published, so the section rows are
  not read then. This keeps the teacher's section page within its query
  budget.

**Appeals** (`grade_appeals`, `Application\Actions\ManageAppeals`).

- The owner sets `grade_settings.appeal_days` (1–90; null turns appeals
  off).
- A student may appeal from their result page from the moment their
  section is published, for that many days. The subject must be of their
  stage and have a mark. They write a reason of 5–500 characters.
- One waiting appeal per subject.
- The owner answers once (accept or reject) with an optional answer the
  student reads. Accepting changes no mark: the owner corrects it on the
  subject's sheet, where the change is recorded (ADR-0041).
- The subject's teacher sees the appeals of their section on the sheet,
  read-only.
- Audited: `grades.appeal_submitted` (no actor) and
  `grades.appeal_decided`.

**Alerts to the department** (`Application\Jobs\NotifyGradesManagers`,
outbox topic `grades.notify_managers`).

- A teacher's request to change marks and a student's appeal each record an
  outbox message in their transaction.
- The job sends a database notification to everyone with `grades.manage`.
  The interface writes the sentence (`notification-text.ts`).

**Warning before marks lock.** In the timed mode, each mark on a
teacher's sheet carries `locks_at`. The page warns when some will lock
within a day. A teacher is not a user, so the warning lives on their page
and is not sent as a notification.

**Absence flag.** The owner sets `grade_settings.absence_flag` (1–50
subjects; null turns it off). A student absent from at least that many
subjects is marked "Frequently absent":

- shown on the owner's results page and in teachers' views;
- never sent to the student's card.

## Consequences

The overview gains one button per section to publish it alone, and a list
of waiting appeals beside the waiting requests.

## Reversal

Set `appeal_days` and `absence_flag` to null, or roll back
`2026_11_19_500000_appeals_publication_absence`.
