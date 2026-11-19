<?php

namespace App\Modules\Grades\Application\Queries;

use App\Modules\Grades\Application\SectionAccess;
use App\Modules\Grades\Domain\EditPolicy;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\TeacherAccess;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\GradeEditRequest;
use App\Modules\Grades\Models\GradeMark;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\GradeSettings;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\SubjectTeacher;
use App\Modules\Grades\Models\Teacher;

/**
 * What a teacher's pages show (ADR-0034), as plain values: their subjects
 * with the progress of each section they teach (and no other), and one section's sheet. A fixed number of
 * queries whatever the number of students (GradesTeacherPagesTest).
 */
final class TeacherSheets
{
    /**
     * @return array{open: bool, subjects: list<array{uuid: string, name: string, stage: int, divided: bool, sections: list<array{section: string, students: int, entered: int}>}>}
     */
    public function subjects(Teacher $teacher, Period $period = Period::Month1, ?GradeSettings $settings = null): array
    {
        $settings ??= GradeSettings::current();
        $window = GradePeriod::for($period);

        // The sections the teacher teaches, less those blocked (ADR-0037).
        $rows = SubjectTeacher::query()
            ->where('teacher_id', $teacher->id)
            ->get(['id', 'subject_id', 'section', 'teacher_id', 'access_mode', 'edit_until'])
            ->reject(fn (SubjectTeacher $row): bool => SectionAccess::hidden($teacher, $row, $settings))
            ->keyBy(fn (SubjectTeacher $row): string => $row->subject_id.'|'.$row->section->value);

        $subjects = Subject::query()
            ->whereIn('id', $rows->pluck('subject_id')->unique()->values())
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
            'open' => $window->isOpen(),
            'subjects' => array_values($subjects->map(fn (Subject $subject): array => [
                'uuid' => $subject->uuid,
                'name' => $subject->name,
                'stage' => $subject->stage->value,
                'divided' => $subject->stage->isDivided(),
                // The stage's sections in order, those the teacher has only.
                'sections' => array_values(array_filter(array_map(function (Section $section) use ($subject, $rows, $students, $entered, $teacher, $settings, $window): ?array {
                    $row = $rows->get($subject->id.'|'.$section->value);

                    return $row === null ? null : [
                        'section' => $section->value,
                        'students' => (int) ($students[$subject->stage->value.'|'.$section->value] ?? 0),
                        'entered' => (int) ($entered[$subject->id.'|'.$section->value] ?? 0),
                        'access' => self::describe(SectionAccess::policy($teacher, $row, $settings, $window)),
                    ];
                }, $subject->stage->sections()))),
            ])->all()),
        ];
    }

    /**
     * One section's students, in name order, with their marks.
     *
     * @return array{open: bool, subject: array{uuid: string, name: string, stage: int}, section: string, students: list<array{uuid: string, name: string, number: string|null, mark: int|string|null}>}
     */
    public function section(Subject $subject, Section $section, Period $period = Period::Month1, ?EditPolicy $policy = null, ?Teacher $teacher = null): array
    {
        $request = null;
        if ($teacher !== null) {
            $request = GradeEditRequest::query()
                ->where('teacher_id', $teacher->id)
                ->where('subject_id', $subject->id)
                ->where('section', $section->value)
                ->where('period', $period->value)
                ->latest('id')
                ->first(['status', 'reason', 'granted_hours', 'created_at', 'decided_at']);
        }

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
            ->get(['student_id', 'mark', 'is_absent', 'created_at'])
            ->keyBy('student_id');

        return [
            'open' => $policy->windowOpen ?? $this->isOpen($period),
            // The teacher's access to this section (null: the owner's sheet).
            'access' => $policy === null ? null : self::describe($policy),
            'request' => $request === null ? null : [
                'status' => $request->status->value,
                'reason' => $request->reason,
                'granted_hours' => $request->granted_hours,
                'created_at' => $request->created_at->toIso8601String(),
                'decided_at' => $request->decided_at?->toIso8601String(),
            ],
            'subject' => ['uuid' => $subject->uuid, 'name' => $subject->name, 'stage' => $subject->stage->value, 'divided' => $subject->stage->isDivided(), 'max' => $subject->max_mark],
            'section' => $section->value,
            'students' => array_values($students->map(fn (Student $student): array => [
                'uuid' => $student->uuid,
                'name' => $student->full_name,
                'number' => $student->student_number,
                'mark' => $marks->get($student->id)?->value()->toScalar(),
                // A mark past its editing time (timed mode, ADR-0037).
                'locked' => $policy !== null && ($row = $marks->get($student->id)) !== null && ! $policy->canChange($row->created_at),
            ])->all()),
        ];
    }

    /**
     * A section's access as the teacher's pages show it (ADR-0037).
     *
     * @return array{state: string, can_write: bool, hours: int|null, until: string|null}
     */
    public static function describe(EditPolicy $policy): array
    {
        $state = match (true) {
            $policy->published => 'published',
            $policy->hasGrant() => 'granted',
            ! $policy->windowOpen => 'closed',
            $policy->mode === TeacherAccess::ViewOnly => 'view_only',
            $policy->mode === TeacherAccess::Timed && $policy->editHours !== null => 'timed',
            default => 'edit',
        };

        return [
            'state' => $state,
            'can_write' => $policy->canWrite(),
            'hours' => $state === 'timed' ? $policy->editHours : null,
            'until' => $state === 'granted' ? $policy->grantUntil?->format(DATE_ATOM) : null,
        ];
    }

    private function isOpen(Period $period): bool
    {
        return GradePeriod::query()->where('period', $period->value)->toBase()->value('status') === WindowStatus::Open->value;
    }
}
