<?php

namespace App\Modules\Grades\Application\Metrics;

use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Models\GradeMark;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Platform\Observability\MetricsSource;
use App\Platform\Observability\MetricsWriter;

/**
 * The Grades module's part of /metrics (ADR-0034): students per stage, and
 * per period the marks entered, the marks expected (each student times the
 * subjects of their stage) and whether entry is open. Every series is
 * exported even at 0, so an alert on it never meets a missing series.
 */
final class GradesMetrics implements MetricsSource
{
    public function name(): string
    {
        return 'grades';
    }

    public function collect(MetricsWriter $metrics): void
    {
        $students = Student::query()->toBase()->selectRaw('stage, count(*) as n')->groupBy('stage')->pluck('n', 'stage');
        $subjects = Subject::query()->toBase()->selectRaw('stage, count(*) as n')->groupBy('stage')->pluck('n', 'stage');
        $entered = GradeMark::query()->toBase()->selectRaw('period, count(*) as n')->groupBy('period')->pluck('n', 'period');
        // Raw values: through the model, pluck would cast them to enums.
        $open = GradePeriod::query()->where('status', 'open')->toBase()->pluck('period')->all();

        $metrics->type('module_grades_students', 'Students per stage.');
        $expected = 0;
        foreach (Stage::cases() as $stage) {
            $count = (int) ($students[$stage->value] ?? 0);
            $metrics->sample('module_grades_students', ['stage' => (string) $stage->value], $count);
            $expected += $count * (int) ($subjects[$stage->value] ?? 0);
        }

        $metrics->type('module_grades_marks_entered', 'Marks entered per period.');
        foreach (Period::cases() as $period) {
            $metrics->sample('module_grades_marks_entered', ['period' => $period->value], (int) ($entered[$period->value] ?? 0));
        }

        $metrics->type('module_grades_marks_expected', 'Marks a period needs: each student times the subjects of their stage.');
        foreach (Period::cases() as $period) {
            $metrics->sample('module_grades_marks_expected', ['period' => $period->value], $expected);
        }

        $metrics->type('module_grades_entry_open', 'Whether teachers may enter marks (1) or not (0).');
        foreach (Period::cases() as $period) {
            $metrics->sample('module_grades_entry_open', ['period' => $period->value], in_array($period->value, $open, true) ? 1 : 0);
        }
    }
}
