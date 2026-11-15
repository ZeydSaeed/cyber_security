<?php

namespace App\Modules\Grades\Application\Queries;

use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\ResultCalculator;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\StudentResult;
use App\Modules\Grades\Models\GradeMark;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use Illuminate\Support\Collection;

/**
 * Results as the owner reads, prints and exports them (ADR-0034): a
 * section's students against the subjects of their stage, each with the
 * result ResultCalculator makes. Three queries whatever the section's size.
 */
final readonly class ResultSheets
{
    public function __construct(private ResultCalculator $calculator) {}

    /**
     * @return array{
     *     stage: int,
     *     section: string,
     *     divided: bool,
     *     pass_mark: int,
     *     subjects: list<array{uuid: string, name: string}>,
     *     students: list<array{uuid: string, name: string, number: string|null, marks: list<int|string|null>, total: int, maximum: int, percentage: float, below: int, absences: int, missing: int, complete: bool, passed: bool}>,
     *     summary: array{students: int, complete: int, passed_all: int}
     * }
     */
    public function section(Stage $stage, Section $section, Period $period = Period::Month1): array
    {
        $students = Student::query()
            ->where('stage', $stage->value)
            ->where('section', $section->value)
            ->orderBy('full_name')
            ->get(['id', 'uuid', 'full_name', 'student_number']);

        return $this->sheet($stage, $students, $period) + ['section' => $section->value, 'divided' => $stage->isDivided()];
    }

    /**
     * One student's result card.
     *
     * @return array{stage: int, section: string, divided: bool, pass_mark: int, subjects: list<array{uuid: string, name: string}>, student: array{uuid: string, name: string, number: string|null, marks: list<int|string|null>, total: int, maximum: int, percentage: float, below: int, absences: int, missing: int, complete: bool, passed: bool}}
     */
    public function student(Student $student, Period $period = Period::Month1): array
    {
        $sheet = $this->sheet($student->stage, collect([$student]), $period);

        return [
            'stage' => $sheet['stage'],
            'section' => $student->section->value,
            'divided' => $student->stage->isDivided(),
            'pass_mark' => $sheet['pass_mark'],
            'subjects' => $sheet['subjects'],
            'student' => $sheet['students'][0],
        ];
    }

    /**
     * @param  Collection<int, Student>  $students
     * @return array{stage: int, pass_mark: int, subjects: list<array{uuid: string, name: string}>, students: list<array{uuid: string, name: string, number: string|null, marks: list<int|string|null>, total: int, maximum: int, percentage: float, below: int, absences: int, missing: int, complete: bool, passed: bool}>, summary: array{students: int, complete: int, passed_all: int}}
     */
    private function sheet(Stage $stage, Collection $students, Period $period): array
    {
        $subjects = Subject::query()
            ->where('stage', $stage->value)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'uuid', 'name']);
        $subjectIds = array_values(array_map('intval', $subjects->pluck('id')->all()));

        $marks = GradeMark::query()
            ->where('stage', $stage->value)
            ->where('period', $period->value)
            ->whereIn('student_id', $students->pluck('id'))
            ->get(['student_id', 'subject_id', 'mark', 'is_absent'])
            ->groupBy('student_id');

        $rows = [];
        $complete = 0;
        $passedAll = 0;

        foreach ($students as $student) {
            $own = [];
            foreach ($marks->get($student->id, collect()) as $mark) {
                $own[$mark->subject_id] = $mark->value();
            }

            $result = $this->calculator->for($subjectIds, $own);
            $complete += $result->isComplete() ? 1 : 0;
            $passedAll += $result->passedAll() ? 1 : 0;
            $rows[] = $this->row($student, $result);
        }

        return [
            'stage' => $stage->value,
            'pass_mark' => (int) config('grades.pass_mark'),
            'subjects' => array_values($subjects->map(fn (Subject $s): array => ['uuid' => $s->uuid, 'name' => $s->name])->all()),
            'students' => $rows,
            'summary' => ['students' => count($rows), 'complete' => $complete, 'passed_all' => $passedAll],
        ];
    }

    /**
     * @return array{uuid: string, name: string, number: string|null, marks: list<int|string|null>, total: int, maximum: int, percentage: float, below: int, absences: int, missing: int, complete: bool, passed: bool}
     */
    private function row(Student $student, StudentResult $result): array
    {
        return [
            'uuid' => $student->uuid,
            'name' => $student->full_name,
            'number' => $student->student_number,
            'marks' => array_values(array_map(fn ($mark) => $mark?->toScalar(), $result->marks)),
            'total' => $result->total,
            'maximum' => $result->maximum,
            'percentage' => $result->percentage,
            'below' => $result->belowPassMark,
            'absences' => $result->absences,
            'missing' => $result->missing,
            'complete' => $result->isComplete(),
            'passed' => $result->passedAll(),
        ];
    }
}
