<?php

namespace App\Modules\Grades\Domain;

/**
 * Turns a student's marks into a result (ADR-0034): the total of the
 * subjects of their stage (each out of 100), the percentage, how many are
 * below the pass mark (an absent subject counts as 0 and as below it), and
 * how many are still missing. No ranking.
 */
final readonly class ResultCalculator
{
    public function __construct(private int $passMark) {}

    /**
     * @param  list<int>  $subjectIds  the subjects of the student's stage
     * @param  array<int, Mark>  $marks  subject id => mark, entered ones only
     */
    public function for(array $subjectIds, array $marks): StudentResult
    {
        $total = 0;
        $below = 0;
        $absences = 0;
        $missing = 0;
        $row = [];

        foreach ($subjectIds as $subjectId) {
            $mark = $marks[$subjectId] ?? null;
            $row[$subjectId] = $mark;

            if ($mark === null) {
                $missing++;

                continue;
            }

            $total += $mark->points();
            $absences += $mark->absent ? 1 : 0;
            $below += $mark->passes($this->passMark) ? 0 : 1;
        }

        $maximum = count($subjectIds) * Mark::MAX;

        return new StudentResult(
            marks: $row,
            total: $total,
            maximum: $maximum,
            percentage: $maximum === 0 ? 0.0 : round($total * 100 / $maximum, 2),
            belowPassMark: $below,
            absences: $absences,
            missing: $missing,
        );
    }
}
