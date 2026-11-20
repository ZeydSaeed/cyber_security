# Grades: full-size end-to-end audit (2026-11-20)

Owner's request: a full Playwright audit of the student, teacher and
administration pages, on test data only (real data untouched), with 100
students, 100 teachers and 100 student results; find and fix logical gaps,
missing information, inconsistent wording and unneeded functions.

## Environment

- Data: `database/audit.sqlite` (SQLite, git-ignored), made by
  `GradesAuditSeeder`, which refuses to run on any other database. The real
  PostgreSQL databases were not opened.
- Server: `php artisan serve` on port 8125 (the browser suite uses 8123/8124).
- Run: `npm run build && npx playwright test -c playwright.audit.config.ts`
  (PHP 8.5 first on PATH, `PLAYWRIGHT_BROWSERS_PATH=.tools/ms-playwright`).
- Not part of CI: `playwright.config.ts` reads `tests/browser` only.

## Scenario (tests/audit/grades-flow.spec.ts)

1. Owner opens every settings tab and page; adds 3 teachers (97 → 100) and
   3 students (97 → 100) through the forms; empty name and duplicate name
   refused; a temporary teacher deleted; a teacher's code replaced; reads
   the new students' codes from the printable slips; sets what students
   see (rank, grades, absences, appeals for one day, teacher replies).
2. 40 teachers sign in (a replaced code and an idle teacher checked), are
   refused another teacher's sheet, enter all 800 marks (some absent; out
   of 50 and out of 100), out-of-range marks flagged, save, reload,
   approve; one asks to change approved marks.
3. Owner approves the request; the teacher changes a mark under the grant;
   owner closes entry and checks **every one of the 100 result rows**
   (total, maximum, percentage) against an independent calculation in the
   test; exports Excel; opens printable pages; publishes the five sections
   one by one.
4. All 100 students sign in and read their card (total and percentage
   checked against the same calculation); six appeal.
5. Owner accepts one appeal and rejects five; corrects the mark on the
   sheet; the student sees the new total; a stopped student and a stopped
   teacher cannot sign in.

## Defects found and fixed

| #   | Finding                                                                                                                                                                                                             | Fix                                                                                                                                                         | Evidence                                                                                          |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| 1   | Approving marks, asking for changes and appealing were limited **per network address** (10 a minute). A school's staff or a class share one address: the 4th teacher approving at once got "429 Too Many Requests". | Named limiters per teacher / per student (`grades-teacher-action`, `grades-teacher-appeal`, `grades-student-appeal`).                                       | `SharedAddressSignInTest` (15 teachers approve from one address; one teacher still stopped at 10) |
| 2   | An approval means "every student has a mark", but a student added, imported or moved into the section afterwards left the approval standing with a missing mark.                                                    | `ApproveSection::releaseForNewcomer`, called by `SaveStudent` and `ImportStudents`; audited as `grades.section_approval_cleared` (reason `student_joined`). | `AuditFindingsTest` (4 cases)                                                                     |
| 3   | The owner decided an appeal without seeing the appealed mark, and had no way to the sheet where it is corrected (the data had the subject but the page did not use it).                                             | The appeal shows the mark (or "absent") out of its maximum and links to the section's sheet.                                                                | `AuditFindingsTest`                                                                               |
| 4   | "Open mark entry" stayed enabled while single sections were published, then the server refused it.                                                                                                                  | Disabled in that case too, with the reason shown.                                                                                                           | build + browser run                                                                               |
| 5   | Arabic wording (see below).                                                                                                                                                                                         | `lang/ar.json`, values only.                                                                                                                                | key scan: 0 missing keys                                                                          |

## Arabic wording corrected

- "Stage" was الصف, the same word the import uses for a row of the file:
  now المرحلة with feminine ordinals (المرحلة الأولى/الثانية/الثالثة) in
  every message. The import still reads both words.
- "Students: sign-in and the result" said students _edit marks_
  (الدخول وتعديل الدرجات للطلاب): now الطلاب: الدخول والنتيجة.
- "Pass rate" and "Pass percentage" were both نسبة النجاح: the rate is now
  نسبة الناجحين. "Mean" is المتوسط الحسابي (Fair stays متوسط).
- An accepted appeal read مقبول, which is also the grade "Pass": now
  قُبل الاعتراض.
- "External" status: منتسب (an adjective, like منتظم).
- "A mark's journey" (رحلة الدرجة) → سجل الدرجة; "Step" → الإجراء.
- One word each: الطلاب (not الطلبة), بطاقة (not كارت).
- Grammar and completeness: المواد والمدرسون; روابط المدرسين والطلاب;
  تحميل النتيجة; "number at the school" → في القسم.

## Owner's decisions (2026-11-20), implemented

- The student import, unreachable from every page, is **removed** (code,
  routes, page, tests, 32 translations). The student number, which only
  the import could set, is now a field of the student's form.
- **Publishing asks first**, saying what is not ready (missing marks,
  unapproved subject sections), for all results and for one section.
- The printed card and its downloaded image show the **student number**,
  never the sign-in code.

Details: ADR-0034, amendment of 2026-11-20.

## Left as is

- 26 translation keys are not used by any page (mostly authentication
  pages of the template); left in place.
