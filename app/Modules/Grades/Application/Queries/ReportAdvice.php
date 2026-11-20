<?php

namespace App\Modules\Grades\Application\Queries;

use App\Modules\Grades\Domain\AppealStatus;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;

/**
 * Recommendations to the department, drawn from the academic reports
 * (owner's request, 2026-11-20). Each is a rule with a stated threshold,
 * so the same figures always give the same advice, and each names its
 * evidence. Levels: critical (act before publishing or now), warning
 * (follow up this period), info (worth knowing).
 *
 * The thresholds are academic rules of thumb, kept here in one place:
 */
final class ReportAdvice
{
    /** A subject pass rate below this is critical. */
    public const PASS_CRITICAL = 50.0;

    /** A subject pass rate below this is a warning. */
    public const PASS_WARNING = 65.0;

    /** A subject's mean this far from its stage's mean (points) is unusual. */
    public const MEAN_GAP = 15.0;

    /** One subject's pass rates in two sections this far apart (points). */
    public const SECTION_GAP = 20.0;

    /** A subject with more absences than this (%) of its entered marks. */
    public const ABSENCE_RATE = 10.0;

    /** Appeals of one subject above this share of its students (%). */
    public const APPEAL_SHARE = 10.0;

    /** Published, yet fewer than this share of the students looked (%). */
    public const VIEW_SHARE = 60.0;

    /**
     * @param  array<string, mixed>  $report  AcademicReports::build()
     * @return list<array{level: string, area: string, text: string, action: string}>
     */
    public function for(array $report): array
    {
        $advice = [];
        $add = function (string $level, string $area, string $text, string $action) use (&$advice): void {
            $advice[] = ['level' => $level, 'area' => $area, 'text' => $text, 'action' => $action];
        };

        // 1. Subject sections nobody teaches.
        foreach ($report['unassigned'] as $row) {
            $add('critical', 'teachers',
                __(':subject in :place has no teacher.', ['subject' => $row['subject'], 'place' => $this->place($row['stage'], $row['section'])]),
                __('Assign a teacher in the teacher\'s form before mark entry closes.'));
        }

        // 2. Teachers not done.
        $late = array_values(array_filter($report['teachers'], fn (array $teacher): bool => in_array($teacher['state'], ['none', 'started', 'complete'], true)));
        if ($late !== []) {
            $none = count(array_filter($late, fn (array $teacher): bool => $teacher['state'] === 'none'));
            $add($report['period']['open'] ? 'warning' : 'critical', 'teachers',
                __(':count teachers have not approved all their marks; :none of them have entered none.', ['count' => count($late), 'none' => $none]),
                __('Remind them, with a date; approval locks the marks and confirms they are final.'));
        }
        $never = array_values(array_filter($report['teachers'], fn (array $teacher): bool => $teacher['assignments'] !== [] && $teacher['logins'] === 0));
        if ($never !== []) {
            $add('warning', 'teachers',
                __(':count teachers with subjects have never signed in: :names.', ['count' => count($never), 'names' => $this->names(array_column($never, 'name'))]),
                __('Check they received their link and code.'));
        }

        // 3. Subjects with a low pass rate, and unusual means.
        foreach ($report['subjects'] as $subject) {
            if ($subject['pass_rate'] === null || $subject['entered'] < 5) {
                continue;
            }
            $where = __(':subject (:stage)', ['subject' => $subject['name'], 'stage' => $this->stage($subject['stage'])]);
            if ($subject['pass_rate'] < self::PASS_CRITICAL) {
                $add('critical', 'subjects',
                    __('Only :rate% passed :where.', ['rate' => $subject['pass_rate'], 'where' => $where]),
                    __('Review the exam against the syllabus with the teacher, and plan support classes and a make-up assessment.'));
            } elseif ($subject['pass_rate'] < self::PASS_WARNING) {
                $add('warning', 'subjects',
                    __(':rate% passed :where, below :limit%.', ['rate' => $subject['pass_rate'], 'where' => $where, 'limit' => self::PASS_WARNING]),
                    __('Follow the next assessment of this subject closely.'));
            }
            if ($subject['difference'] !== null && abs($subject['difference']) >= self::MEAN_GAP) {
                $add('warning', 'subjects',
                    __('The mean of :where is :difference points from its stage\'s mean.', ['where' => $where, 'difference' => ($subject['difference'] > 0 ? '+' : '').$subject['difference']]),
                    $subject['difference'] > 0
                        ? __('Check the marking is not lenient: compare a sample of papers with another teacher.')
                        : __('Check the exam\'s difficulty and length against the other subjects.'));
            }
            if ($subject['absence_rate'] !== null && $subject['absence_rate'] > self::ABSENCE_RATE) {
                $add('warning', 'students',
                    __(':rate% of the marks of :where are absences.', ['rate' => $subject['absence_rate'], 'where' => $where]),
                    __('Follow up the absent students and offer a make-up exam where the rules allow.'));
            }

            // 4. One subject, two sections, far apart.
            $rates = array_values(array_filter(array_map(fn (array $section): ?float => $section['entered'] >= 5 ? $section['pass_rate'] : null, $subject['sections']), fn (?float $rate): bool => $rate !== null));
            if (count($rates) >= 2 && max($rates) - min($rates) >= self::SECTION_GAP) {
                $add('warning', 'subjects',
                    __('The sections of :where differ by :gap points in pass rate.', ['where' => $where, 'gap' => round(max($rates) - min($rates), 1)]),
                    __('Unify the exam and the marking scheme between the sections\' teachers.'));
            }
        }

        // 5. Students at risk.
        $totals = $report['totals'];
        if ($totals['failed_two_or_more'] > 0) {
            $add('warning', 'students',
                __(':count students failed two subjects or more.', ['count' => $totals['failed_two_or_more']]),
                __('Give each an academic adviser and a support plan before the next exams.'));
        }
        if ($totals['frequent_absence'] > 0) {
            $add('warning', 'students',
                __(':count students are flagged for frequent absence.', ['count' => $totals['frequent_absence']]),
                __('Contact them and their guardians; absence is the earliest sign of failing.'));
        }

        // 6. Publication and the students.
        $published = array_filter($report['period']['places'], fn (array $place): bool => $place['published_at'] !== null);
        $out = array_map(fn (array $place): string => "{$place['stage']}|{$place['section']}", $published);
        $incomplete = (int) array_sum(array_map(
            fn (array $section): int => in_array("{$section['stage']}|{$section['section']}", $out, true) ? $section['incomplete'] : 0,
            $report['sections'],
        ));
        if ($incomplete > 0) {
            $add('critical', 'publication',
                __(':count students have published results with missing marks.', ['count' => $incomplete]),
                __('Complete their marks; a published result should be final.'));
        }
        if ($published !== []) {
            $students = array_values(array_filter($report['students'], fn (array $student): bool => in_array("{$student['stage']}|{$student['section']}", $out, true)));
            $seen = count(array_filter($students, fn (array $student): bool => $student['views'] > 0));
            $share = $students === [] ? null : round($seen * 100 / count($students), 1);
            if ($share !== null && $share < self::VIEW_SHARE) {
                $add('info', 'publication',
                    __('Only :share% of the students whose results are published have looked at them.', ['share' => $share]),
                    __('Announce the results again in class and on the department\'s page, with the student link.'));
            }
        }

        // 7. Appeals.
        $pending = count(array_filter($report['appeals'], fn (array $appeal): bool => $appeal['status'] === AppealStatus::Pending->value));
        if ($pending > 0) {
            $add('warning', 'appeals',
                __(':count appeals wait for an answer.', ['count' => $pending]),
                __('Answer them within the appeal period, with a reason the student can read.'));
        }
        $bySubject = [];
        foreach ($report['appeals'] as $appeal) {
            $bySubject[$appeal['subject']] = ($bySubject[$appeal['subject']] ?? 0) + 1;
        }
        foreach ($report['subjects'] as $subject) {
            $count = $bySubject[$subject['name']] ?? 0;
            if ($count >= 2 && $subject['students'] > 0 && $count * 100 / $subject['students'] > self::APPEAL_SHARE) {
                $add('warning', 'appeals',
                    __(':count students appealed :subject.', ['count' => $count, 'subject' => $subject['name']]),
                    __('Have a second teacher re-mark a sample; many appeals point at the marking scheme.'));
            }
        }
        $accepted = count(array_filter($report['appeals'], fn (array $appeal): bool => $appeal['status'] === AppealStatus::Accepted->value));
        if ($accepted >= 3) {
            $add('info', 'appeals',
                __(':count appeals were accepted: marks were corrected after publication.', ['count' => $accepted]),
                __('Ask teachers to check their marks before approving them.'));
        }

        $order = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($advice, fn (array $a, array $b): int => $order[$a['level']] <=> $order[$b['level']]);

        if ($advice === []) {
            $add('info', 'general',
                __('Nothing needs attention in these figures.'),
                __('Keep the same timetable for the next period.'));
        }

        return $advice;
    }

    private function stage(int $stage): string
    {
        return match (Stage::from($stage)) {
            Stage::First => __('First stage'),
            Stage::Second => __('Second stage'),
            Stage::Third => __('Third stage'),
        };
    }

    private function place(int $stage, string $section): string
    {
        $value = Stage::from($stage);

        return $value->isDivided()
            ? $this->stage($stage).' · '.__('Section :section', ['section' => Section::from($section)->value])
            : $this->stage($stage);
    }

    /**
     * @param  list<string>  $names
     */
    private function names(array $names): string
    {
        $shown = array_slice($names, 0, 5);
        $more = count($names) - count($shown);

        return implode('، ', $shown).($more > 0 ? ' '.__('and :count more', ['count' => $more]) : '');
    }
}
