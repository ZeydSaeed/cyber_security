<?php

namespace App\Modules\Grades\Application\Queries;

use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\GradeMark;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;

/**
 * What a teacher's pages show (ADR-0034), as plain values: their subjects
 * with each section's progress, and one section's sheet. A fixed number of
 * queries whatever the number of students (GradesTeacherPagesTest).
 */
final class TeacherSheets
{
    /**
     * @return array{open: bool, subjects: list<array{uuid: string, name: string, stage: int, sections: list<array{section: string, students: int, entered: int}>}>}
     */
    public function subjects(Teacher $teacher, Period $period = Period::Month1): array
    {
        $subjects = Subject::query()
            ->where('teacher_id', $teacher->id)
            ->orderBy('stage')->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'uuid', 'name', 'stage']);

        $stages = $subjects->map(fn (Subject $subject): int => $subject->stage->value)->unique()->values()->all();

        // Students per stage and section, and marks per subject and section.
        $students = Student::query()->toBase()
            ->whereIn('stage', $stages)
            ->selectRaw('stage, section, count(*) as n')
            ->groupBy('stage', 'section')
            ->get()
            ->mapWithKeys(fn (object $row): array => [$row->stage.'|'.$row->section => (int) $row->n]);

        $entered = GradeMark::query()->toBase()
            ->join('grade_students', 'grade_students.id', '=', 'grade_marks.student_id')
            ->whereIn('grade_marks.subject_id', $subjects->pluck('id'))
            ->where('grade_marks.period', $period->value)
            ->selectRaw('grade_marks.subject_id, grade_students.section, count(*) as n')
            ->groupBy('grade_marks.subject_id', 'grade_students.section')
            ->get()
            ->mapWithKeys(fn (object $row): array => [$row->subject_id.'|'.$row->section => (int) $row->n]);

        return [
            'open' => $this->isOpen($period),
            'subjects' => array_values($subjects->map(fn (Subject $subject): array => [
                'uuid' => $subject->uuid,
                'name' => $subject->name,
                'stage' => $subject->stage->value,
                'sections' => array_map(fn (Section $section): array => [
                    'section' => $section->value,
                    'students' => (int) ($students[$subject->stage->value.'|'.$section->value] ?? 0),
                    'entered' => (int) ($entered[$subject->id.'|'.$section->value] ?? 0),
                ], Section::cases()),
            ])->all()),
        ];
    }

    /**
     * One section's students, in name order, with their marks.
     *
     * @return array{open: bool, subject: array{uuid: string, name: string, stage: int}, section: string, students: list<array{uuid: string, name: string, number: string|null, mark: int|string|null}>}
     */
    public function section(Subject $subject, Section $section, Period $period = Period::Month1): array
    {
        $students = Student::query()
            ->where('stage', $subject->stage->value)
            ->where('section', $section->value)
            ->orderBy('full_name')
            ->get(['id', 'uuid', 'full_name', 'student_number']);

        $marks = GradeMark::query()
            ->where('subject_id', $subject->id)
            ->where('stage', $subject->stage->value)
            ->where('period', $period->value)
            ->whereIn('student_id', $students->pluck('id'))
            ->get(['student_id', 'mark', 'is_absent'])
            ->keyBy('student_id');

        return [
            'open' => $this->isOpen($period),
            'subject' => ['uuid' => $subject->uuid, 'name' => $subject->name, 'stage' => $subject->stage->value],
            'section' => $section->value,
            'students' => array_values($students->map(fn (Student $student): array => [
                'uuid' => $student->uuid,
                'name' => $student->full_name,
                'number' => $student->student_number,
                'mark' => $marks->get($student->id)?->value()->toScalar(),
            ])->all()),
        ];
    }

    private function isOpen(Period $period): bool
    {
        return GradePeriod::query()->where('period', $period->value)->toBase()->value('status') === WindowStatus::Open->value;
    }
}
