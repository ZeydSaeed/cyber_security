<?php

namespace App\Modules\Grades\Application\Queries;

use App\Modules\Grades\Domain\AppealStatus;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Models\GradeAppeal;

/**
 * The appeals of a period by status, and who answered them: the appeal
 * tiles of the owner's overview (ADR-0044).
 */
final class AppealCounts
{
    /**
     * @return array{pending: int, accepted: int, rejected: int, by_teacher: int, by_department: int}
     */
    public static function for(Period $period): array
    {
        $byStatus = GradeAppeal::query()
            ->where('period', $period->value)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $answered = GradeAppeal::query()
            ->where('period', $period->value)
            ->where('status', '!=', AppealStatus::Pending->value)
            ->selectRaw('count(decided_by_teacher_id) as by_teacher, count(*) as total')
            ->first();
        $byTeacher = (int) ($answered->by_teacher ?? 0);

        return [
            'pending' => (int) ($byStatus[AppealStatus::Pending->value] ?? 0),
            'accepted' => (int) ($byStatus[AppealStatus::Accepted->value] ?? 0),
            'rejected' => (int) ($byStatus[AppealStatus::Rejected->value] ?? 0),
            'by_teacher' => $byTeacher,
            'by_department' => (int) ($answered->total ?? 0) - $byTeacher,
        ];
    }
}
