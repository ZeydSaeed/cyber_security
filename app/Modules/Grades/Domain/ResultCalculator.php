<?php

namespace App\Modules\Grades\Domain;

/**
 * Turns a student's marks into a result (ADR-0034, ADR-0039): the total of
 * the subjects of their stage against the sum of their maximums, the
 * percentage, how many are below their pass mark (an absent subject counts
 * as 0 and as below it), how many are still missing; for each mark its
 * percentage, band and individual exemption; and, once complete, the
 * overall band and the general exemption.
 */
final readonly class ResultCalculator
{
    public function __construct(
        private GradeBands $bands,
        /** The percentage that exempts a student who passed everything (null: none). */
        private ?int $exemptOverall = null,
    ) {}

    /**
     * @param  array<int, SubjectScale>  $scales  the subjects of the student's stage, in order: id => scale
     * @param  array<int, Mark>  $marks  subject id => mark, entered ones only
     */
    public function for(array $scales, array $marks): StudentResult
    {
        $total = 0;
        $maximum = 0;
        $below = 0;
        $absences = 0;
        $missing = 0;
        $row = [];
        $cells = [];

        foreach ($scales as $subjectId => $scale) {
            $mark = $marks[$subjectId] ?? null;
            $row[$subjectId] = $mark;
            $maximum += $scale->max;

            if ($mark === null) {
                $missing++;
                $cells[$subjectId] = null;

                continue;
            }

            $passed = $scale->passes($mark);
            $percent = $scale->percent($mark);
            $total += $mark->points();
            $absences += $mark->absent ? 1 : 0;
            $below += $passed ? 0 : 1;
            $cells[$subjectId] = [
                'percent' => $percent,
                'passed' => $passed,
                'band' => $this->bands->for($percent, $passed),
                'exempt' => $scale->exempts($mark),
            ];
        }

        $percentage = $maximum === 0 ? 0.0 : round($total * 100 / $maximum, 2);
        $complete = $missing === 0 && $scales !== [];
        $passedAll = $complete && $below === 0;

        return new StudentResult(
            marks: $row,
            total: $total,
            maximum: $maximum,
            percentage: $percentage,
            belowPassMark: $below,
            absences: $absences,
            missing: $missing,
            cells: $cells,
            band: $complete ? $this->bands->for($percentage, $passedAll) : null,
            exemptAll: $passedAll && $this->exemptOverall !== null && $percentage >= $this->exemptOverall,
        );
    }

    /**
     * Ranks within a group by percentage, among complete results only;
     * equal percentages share a rank and the next rank is skipped
     * (1, 1, 3), the owner's choice of 2026-11-19.
     *
     * @param  array<array-key, StudentResult>  $results
     * @return array<array-key, int|null> same keys => rank (null: incomplete)
     */
    public static function ranks(array $results): array
    {
        $complete = array_filter($results, fn (StudentResult $result): bool => $result->isComplete());
        $percentages = array_map(fn (StudentResult $result): float => $result->percentage, $complete);
        arsort($percentages);

        $ranks = array_fill_keys(array_keys($results), null);
        $position = 0;
        $rank = 0;
        $last = null;
        foreach ($percentages as $key => $percentage) {
            $position++;
            if ($last === null || $percentage < $last) {
                $rank = $position;
                $last = $percentage;
            }
            $ranks[$key] = $rank;
        }

        return $ranks;
    }
}
