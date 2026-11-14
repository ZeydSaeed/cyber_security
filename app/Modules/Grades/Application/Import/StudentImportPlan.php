<?php

namespace App\Modules\Grades\Application\Import;

/**
 * What a student import would do (ADR-0034): shown to the owner as a
 * preview, then applied unchanged.
 */
final readonly class StudentImportPlan
{
    /**
     * @param  list<array{row: int, full_name: string, name_key: string, stage: int, section: string, student_number: string|null}>  $creates
     * @param  list<array{row: int, id: int, full_name: string, changes: array<string, array{from: string|int|null, to: string|int|null}>, set: array<string, string|int|null>}>  $updates
     * @param  list<array{row: int, reason: string}>  $rejected
     */
    public function __construct(
        public array $creates,
        public array $updates,
        public int $unchanged,
        public array $rejected,
        public int $notInFile,
    ) {}

    public function changesSomething(): bool
    {
        return $this->creates !== [] || $this->updates !== [];
    }

    /**
     * @return array{created: int, updated: int, unchanged: int, rejected: int, not_in_file: int}
     */
    public function counts(): array
    {
        return [
            'created' => count($this->creates),
            'updated' => count($this->updates),
            'unchanged' => $this->unchanged,
            'rejected' => count($this->rejected),
            'not_in_file' => $this->notInFile,
        ];
    }
}
