<?php

namespace App\Modules\Grades\Application\Queries;

use App\Modules\Grades\Contracts\GradesOverview;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\GradeMark;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;

/**
 * Two grouped counts and three small ones (ADR-0034), all on indexes or
 * small tables: a department has hundreds of students, not millions.
 */
class GradesOverviewQuery implements GradesOverview
{
    public function progress(): array
    {
        $period = Period::Month1;

        $perStage = Student::query()->toBase()
            ->leftJoinSub(
                Subject::query()->toBase()->selectRaw('stage, count(*) as subjects')->groupBy('stage'),
                's',
                's.stage',
                '=',
                'grade_students.stage',
            )
            ->selectRaw('count(*) as students, coalesce(sum(coalesce(s.subjects, 0)), 0) as expected')
            ->first();

        return [
            'period' => $period->value,
            'open' => GradePeriod::query()->where('period', $period->value)->toBase()->value('status') === WindowStatus::Open->value,
            'entered' => GradeMark::query()->where('period', $period->value)->count(),
            'expected' => (int) ($perStage->expected ?? 0),
            'students' => (int) ($perStage->students ?? 0),
            'subjects' => Subject::query()->count(),
            'teachers' => Teacher::query()->where('is_active', true)->count(),
        ];
    }
}
