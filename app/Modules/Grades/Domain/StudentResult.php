<?php

namespace App\Modules\Grades\Domain;

/**
 * One student's result for a period (ADR-0034, ADR-0039).
 */
final readonly class StudentResult
{
    /**
     * @param  array<int, Mark|null>  $marks  subject id => mark (null: not entered yet)
     * @param  array<int, array{percent: float, passed: bool, band: GradeBand, exempt: bool}|null>  $cells  subject id => what the mark earns (null: not entered)
     */
    public function __construct(
        public array $marks,
        public int $total,
        public int $maximum,
        public float $percentage,
        public int $belowPassMark,
        public int $absences,
        public int $missing,
        public array $cells = [],
        public ?GradeBand $band = null,
        public bool $exemptAll = false,
    ) {}

    public function isComplete(): bool
    {
        return $this->missing === 0;
    }

    public function passedAll(): bool
    {
        return $this->isComplete() && $this->belowPassMark === 0;
    }
}
