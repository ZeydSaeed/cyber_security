<?php

namespace App\Modules\Grades\Domain;

/**
 * One student's result for a period (ADR-0034).
 */
final readonly class StudentResult
{
    /**
     * @param  array<int, Mark|null>  $marks  subject id => mark (null: not entered yet)
     */
    public function __construct(
        public array $marks,
        public int $total,
        public int $maximum,
        public float $percentage,
        public int $belowPassMark,
        public int $absences,
        public int $missing,
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
