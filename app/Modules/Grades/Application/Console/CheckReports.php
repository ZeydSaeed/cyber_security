<?php

namespace App\Modules\Grades\Application\Console;

use App\Modules\Grades\Application\Queries\AcademicReports;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Models\GradeSettings;
use App\Platform\Audit\AuditEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Checks the academic reports against the raw tables (owner's request,
 * 2026-11-20): every figure below is counted again here with plain
 * queries and arithmetic of its own (not ResultSheets, not
 * ResultCalculator), then compared with what the reports say. Read only;
 * safe on any database. Exit code 1 when anything differs.
 */
class CheckReports extends Command
{
    protected $signature = 'grades:reports-check {--period=month_1 : The period to check}';

    protected $description = 'Recount the academic reports\' figures from the raw tables and compare';

    public function handle(AcademicReports $reports): int
    {
        $period = Period::tryFrom((string) $this->option('period')) ?? Period::Month1;
        $report = $reports->build($period);
        $raw = $this->recount($period);
        $rows = [];

        foreach ($report['sections'] as $section) {
            $key = "{$section['stage']}{$section['section']}";
            $own = $raw['sections'][$key] ?? ['students' => 0, 'complete' => 0, 'passed' => 0, 'percent_sum' => 0.0];
            foreach (['students', 'complete', 'passed'] as $field) {
                $rows[] = ["{$key} {$field}", $section[$field], $own[$field]];
            }
            $rows[] = ["{$key} mean %", $section['mean'], $own['complete'] === 0 ? null : round($own['percent_sum'] / $own['complete'], 1)];
        }

        foreach ($report['subjects'] as $subject) {
            $own = $raw['subjects'][$subject['uuid']] ?? ['entered' => 0, 'absent' => 0, 'passed' => 0];
            foreach (['entered', 'absent', 'passed'] as $field) {
                $rows[] = ["{$subject['name']} ({$subject['stage']}) {$field}", $subject[$field], $own[$field]];
            }
        }

        $rows[] = ['appeals', count($report['appeals']), $raw['appeals']];
        $rows[] = ['approved subject sections', array_sum(array_column($report['teachers'], 'approved')), $raw['approvals']];
        $rows[] = ['teacher sign-ins (counter)', array_sum(array_column($report['teachers'], 'logins')), $raw['teacher_logins']];
        $rows[] = ['student sign-ins (counter)', array_sum(array_column($report['students'], 'logins')), $raw['student_logins']];
        $rows[] = ['student views of results', array_sum(array_column($report['students'], 'views')), $raw['views']];
        $rows[] = ['subject sections without a teacher', count($report['unassigned']), $raw['unassigned']];

        $failed = 0;
        $table = [];
        foreach ($rows as [$what, $reported, $counted]) {
            $same = $reported === $counted || (is_numeric($reported) && is_numeric($counted) && abs((float) $reported - (float) $counted) < 0.05);
            $failed += $same ? 0 : 1;
            $table[] = [$same ? 'ok' : 'DIFFERS', $what, (string) json_encode($reported), (string) json_encode($counted)];
        }

        $this->table(['', 'figure', 'reports', 'recounted'], $table);
        if ($failed > 0) {
            $this->error("{$failed} of ".count($rows).' figures differ.');

            return self::FAILURE;
        }
        $this->info('All '.count($rows).' figures agree.');

        return self::SUCCESS;
    }

    /**
     * The same figures from the tables, by hand.
     *
     * @return array{sections: array<string, array{students: int, complete: int, passed: int, percent_sum: float}>, subjects: array<string, array{entered: int, absent: int, passed: int}>, appeals: int, approvals: int, teacher_logins: int, student_logins: int, views: int, unassigned: int}
     */
    private function recount(Period $period): array
    {
        $passPercent = GradeSettings::current()->pass_percent;
        $subjects = DB::table('grade_subjects')->get(['id', 'uuid', 'stage', 'max_mark', 'pass_mark']);
        $students = DB::table('grade_students')->get(['id', 'stage', 'section']);
        $marks = DB::table('grade_marks')->where('period', $period->value)->get(['student_id', 'subject_id', 'mark', 'is_absent']);

        $byStudent = [];
        foreach ($marks as $mark) {
            $byStudent[$mark->student_id][$mark->subject_id] = $mark;
        }
        $passOf = [];
        foreach ($subjects as $subject) {
            $passOf[$subject->id] = $subject->pass_mark ?? (int) ceil($subject->max_mark * $passPercent / 100);
        }

        $sections = [];
        $perSubject = [];
        foreach ($students as $student) {
            $key = $student->stage.$student->section;
            $sections[$key] ??= ['students' => 0, 'complete' => 0, 'passed' => 0, 'percent_sum' => 0.0];
            $sections[$key]['students']++;

            $own = array_values(array_filter($subjects->all(), fn (object $subject): bool => (int) $subject->stage === (int) $student->stage));
            $complete = $own !== [];
            $passed = true;
            $total = 0;
            $maximum = 0;
            foreach ($own as $subject) {
                $mark = $byStudent[$student->id][$subject->id] ?? null;
                $maximum += (int) $subject->max_mark;
                if ($mark === null) {
                    $complete = false;

                    continue;
                }
                $absent = (bool) $mark->is_absent;
                $value = $absent ? 0 : (int) $mark->mark;
                $total += $value;
                $ok = ! $absent && $value >= $passOf[$subject->id];
                $passed = $passed && $ok;

                $perSubject[$subject->uuid] ??= ['entered' => 0, 'absent' => 0, 'passed' => 0];
                $perSubject[$subject->uuid]['entered']++;
                $perSubject[$subject->uuid]['absent'] += $absent ? 1 : 0;
                $perSubject[$subject->uuid]['passed'] += $ok ? 1 : 0;
            }
            if ($complete) {
                $sections[$key]['complete']++;
                $sections[$key]['passed'] += $passed ? 1 : 0;
                $sections[$key]['percent_sum'] += $maximum === 0 ? 0.0 : round($total * 100 / $maximum, 2);
            }
        }

        $assigned = DB::table('grade_subject_teachers')->count();
        $places = 0;
        foreach ($subjects as $subject) {
            $places += count(Stage::from((int) $subject->stage)->sections());
        }

        return [
            'sections' => $sections,
            'subjects' => $perSubject,
            'appeals' => DB::table('grade_appeals')->where('period', $period->value)->count(),
            'approvals' => DB::table('grade_section_approvals')->where('period', $period->value)->count(),
            'teacher_logins' => (int) DB::table('grade_teachers')->sum('login_count'),
            'student_logins' => (int) DB::table('grade_students')->sum('login_count'),
            'views' => AuditEvent::query()->where('action', 'grades.student_result_viewed')->count(),
            'unassigned' => $places - $assigned,
        ];
    }
}
