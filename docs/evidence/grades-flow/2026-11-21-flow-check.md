# Grades full data-flow check, 2026-11-21

Command: `php artisan grades:flow-check` (ADR-0034, amendment of 2026-11-21).
Environment: local development, PHP 8.5 (Herd), PostgreSQL database
`cyber_security`, role `cyber_app`. No production system was touched.

Backup taken before any change:
`storage/app/private/backups/grades-before-full-flow-20261120-143104.json`
(all `grade_*` tables; audit events up to id 1121).

## Runs

| Run      | Mode        |  Checks | Failed | Result             |
| -------- | ----------- | ------: | -----: | ------------------ |
| Dry run  | rolled back | 534,385 |      0 | database unchanged |
| Kept run | `--keep`    | 534,385 |      0 | data below saved   |

Checks per area (identical in both runs):

| Area                                                                |  Checks |
| ------------------------------------------------------------------- | ------: |
| fill                                                                |       2 |
| control panel                                                       |     267 |
| results                                                             |  72,052 |
| student view                                                        |   9,367 |
| teacher view                                                        | 433,049 |
| access                                                              |  16,500 |
| student sign-in                                                     |      12 |
| entry and publication                                               |   2,100 |
| appeals                                                             |   1,005 |
| appeal answers                                                      |      24 |
| final state                                                         |       5 |
| reports (`grades:reports-check`)                                    |       1 |
| untouched (users, roles, teachers, subjects, assignments, students) |       1 |

Machine-readable report of the kept run: `2026-11-21-flow-check-keep.json`.

## State after the kept run (read back from the database)

- Marks: 1,463 (1A 55 × 12 subjects, 2A + 2B 73 × 11), entered by the
  sections' own teachers on 34 sheets; 29 sections approved.
- Edit requests: 4 (1 approved and used, 1 rejected, 2 pending).
- Students with notes: 12.
- Month 1: entry closed, results published to every section.
- Appeals: 25 (5 accepted with the mark corrected, 8 rejected, 12 pending).
- Unchanged: 3 users, 91 teachers, 32 subjects, 34 assignments, 128 students
  (hash comparison before and after: passed).

The marks are made up for testing and do not belong to any real student.

## Defects found and fixed

See ADR-0034, amendment of 2026-11-21, items 1–6, pinned by
`tests/Feature/Grades/FlowAuditFindingsTest.php`.
