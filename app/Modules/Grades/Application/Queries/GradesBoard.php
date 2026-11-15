<?php

namespace App\Modules\Grades\Application\Queries;

use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\GradeMark;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;

/**
 * The owner's overview (ADR-0034): the entry window, and for each stage its
 * subjects with each section's progress (marks entered of students), plus
 * what is still missing to start (subjects without a teacher, a stage
 * without its expected number of subjects). Four grouped queries.
 */
final class GradesBoard
{
    /**
     * @return array{
     *     window: array{open: bool, published: bool, opened_at: string|null, closed_at: string|null},
     *     expected_subjects: int,
     *     teachers: int,
     *     stages: list<array{stage: int, divided: bool, students: array<string, int>, subjects: list<array{uuid: string, name: string, teacher: string|null, sections: array<string, int>}>}>
     * }
     */
    public function show(Period $period = Period::Month1): array
    {
        $window = GradePeriod::for($period);

        $students = Student::query()->toBase()
            ->selectRaw('stage, section, count(*) as n')->groupBy('stage', 'section')->get()
            ->mapWithKeys(fn (object $row): array => [$row->stage.'|'.$row->section => (int) $row->n]);

        $entered = GradeMark::query()->toBase()
            ->join('grade_students', 'grade_students.id', '=', 'grade_marks.student_id')
            ->where('grade_marks.period', $period->value)
            ->selectRaw('grade_marks.subject_id, grade_students.section, count(*) as n')
            ->groupBy('grade_marks.subject_id', 'grade_students.section')->get()
            ->mapWithKeys(fn (object $row): array => [$row->subject_id.'|'.$row->section => (int) $row->n]);

        $subjects = Subject::query()->with('teacher:id,name')
            ->orderBy('stage')->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'uuid', 'name', 'stage', 'teacher_id'])
            ->groupBy(fn (Subject $subject): int => $subject->stage->value);

        $stages = [];
        foreach (Stage::cases() as $stage) {
            $counts = [];
            foreach ($stage->sections() as $section) {
                $counts[$section->value] = (int) ($students[$stage->value.'|'.$section->value] ?? 0);
            }

            $sectionValues = array_keys($counts);

            $stages[] = [
                'stage' => $stage->value,
                'divided' => $stage->isDivided(),
                'students' => $counts,
                'subjects' => array_values($subjects->get($stage->value, collect())->map(fn (Subject $subject): array => [
                    'uuid' => $subject->uuid,
                    'name' => $subject->name,
                    'teacher' => $subject->teacher?->name,
                    'sections' => array_combine(
                        $sectionValues,
                        array_map(fn (string $section): int => (int) ($entered[$subject->id.'|'.$section] ?? 0), $sectionValues),
                    ),
                ])->all()),
            ];
        }

        return [
            'window' => [
                'open' => $window->status === WindowStatus::Open,
                'published' => $window->isPublished(),
                'opened_at' => $window->opened_at?->toIso8601String(),
                'closed_at' => $window->closed_at?->toIso8601String(),
            ],
            'expected_subjects' => (int) config('grades.subjects_per_stage'),
            'teachers' => Teacher::query()->where('is_active', true)->count(),
            'stages' => $stages,
        ];
    }
}
