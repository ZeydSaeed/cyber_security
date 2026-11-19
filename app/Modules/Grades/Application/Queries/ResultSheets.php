<?php

namespace App\Modules\Grades\Application\Queries;

use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\ResultCalculator;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\StudentResult;
use App\Modules\Grades\Models\GradeMark;
use App\Modules\Grades\Models\GradeSettings;
use App\Modules\Grades\Models\GradeStudentNote;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\SubjectTeacher;
use Illuminate\Support\Collection;

/**
 * Results as the owner reads, prints and exports them (ADR-0034,
 * ADR-0039): a section's students against the subjects of their stage,
 * each subject with its maximum, pass and exemption marks and the
 * section's teacher, each student with what ResultCalculator makes, their
 * rank in the section, behaviour and notes. A fixed number of queries
 * whatever the section's size.
 *
 * A student's own card is cut to what the owner chose to show
 * (`forStudent`): hidden parts are not sent at all.
 */
final class ResultSheets
{
    /**
     * @return array<string, mixed>
     */
    public function section(Stage $stage, Section $section, Period $period = Period::Month1): array
    {
        $students = Student::query()
            ->where('stage', $stage->value)
            ->where('section', $section->value)
            ->orderBy('full_name')
            ->get(['id', 'uuid', 'full_name', 'student_number', 'status']);

        return $this->sheet($stage, $section, $students, $period);
    }

    /**
     * One student's result card, as the owner sees it (everything).
     *
     * @return array<string, mixed>
     */
    public function student(Student $student, Period $period = Period::Month1): array
    {
        $sheet = $this->section($student->stage, $student->section, $period);
        $row = null;
        foreach ($sheet['students'] as $candidate) {
            if ($candidate['uuid'] === $student->uuid) {
                $row = $candidate;
            }
        }
        unset($sheet['students'], $sheet['summary']);

        return [...$sheet, 'student' => $row];
    }

    /**
     * One student's card for the student (ADR-0039): only what the owner
     * chose to show is sent.
     *
     * @return array<string, mixed>
     */
    public function forStudent(Student $student, Period $period = Period::Month1): array
    {
        $card = $this->student($student, $period);
        $options = $card['options'];
        $hidden = [
            'total' => ['total', 'maximum'],
            'percentage' => ['percentage'],
            'rank' => ['rank'],
            'failed_count' => ['below'],
            'overall_grade' => ['band'],
            'exemption' => ['exempt_all'],
            'student_status' => ['status'],
            'behaviour' => ['behaviour'],
            'notes' => ['notes'],
        ];

        foreach ($hidden as $option => $fields) {
            if (! $options[$option]) {
                foreach ($fields as $field) {
                    $card['student'][$field] = null;
                }
            }
        }

        $card['student']['cells'] = array_map(fn (?array $cell): ?array => $cell === null ? null : [
            ...$cell,
            'band' => $options['mark_grade'] ? $cell['band'] : null,
            'exempt' => $options['exemption'] && $cell['exempt'],
        ], $card['student']['cells']);
        $card['subjects'] = array_map(fn (array $subject): array => [
            ...$subject,
            'teacher' => $options['teachers'] ? $subject['teacher'] : null,
            'assistant' => $options['teachers'] ? $subject['assistant'] : null,
            'kind' => $options['kind'] ? $subject['kind'] : null,
        ], $card['subjects']);

        return $card;
    }

    /**
     * @param  Collection<int, Student>  $students
     * @return array<string, mixed>
     */
    private function sheet(Stage $stage, Section $section, Collection $students, Period $period): array
    {
        $settings = GradeSettings::current();
        $calculator = $settings->calculator();

        $subjects = Subject::query()
            ->where('stage', $stage->value)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'uuid', 'name', 'max_mark', 'pass_mark', 'exempt_mark']);
        $scales = [];
        foreach ($subjects as $subject) {
            $scales[$subject->id] = $subject->scale($settings);
        }

        $teaching = SubjectTeacher::query()
            ->with(['teacher:id,name', 'assistant:id,name'])
            ->whereIn('subject_id', $subjects->pluck('id'))
            ->where('section', $section->value)
            ->get()
            ->keyBy('subject_id');

        $marks = GradeMark::query()
            ->where('stage', $stage->value)
            ->where('period', $period->value)
            ->whereIn('student_id', $students->pluck('id'))
            ->get(['student_id', 'subject_id', 'mark', 'is_absent'])
            ->groupBy('student_id');

        $notes = GradeStudentNote::query()
            ->where('period', $period->value)
            ->whereIn('student_id', $students->pluck('id'))
            ->get(['student_id', 'behaviour', 'notes'])
            ->keyBy('student_id');

        $results = [];
        foreach ($students as $student) {
            $own = [];
            foreach ($marks->get($student->id, collect()) as $mark) {
                $own[$mark->subject_id] = $mark->value();
            }
            $results[$student->id] = $calculator->for($scales, $own);
        }
        $ranks = ResultCalculator::ranks($results);

        $rows = [];
        foreach ($students as $student) {
            $note = $notes->get($student->id);
            $rows[] = $this->row($student, $results[$student->id], $ranks[$student->id], $note);
        }

        return [
            'stage' => $stage->value,
            'section' => $section->value,
            'divided' => $stage->isDivided(),
            'pass_percent' => $settings->pass_percent,
            'exempt_overall' => $settings->exempt_overall_percent,
            'options' => $settings->options(),
            'tones' => $settings->tones(),
            'subjects' => array_values($subjects->map(function (Subject $subject) use ($scales, $teaching): array {
                $scale = $scales[$subject->id];
                $row = $teaching->get($subject->id);

                return [
                    'uuid' => $subject->uuid,
                    'name' => $subject->name,
                    'max' => $scale->max,
                    'pass' => $scale->pass,
                    'exempt' => $scale->exempt,
                    'teacher' => $row?->teacher?->name,
                    'assistant' => $row?->assistant?->name,
                    'kind' => $row?->kind?->value,
                ];
            })->all()),
            'students' => $rows,
            'summary' => [
                'students' => count($rows),
                'complete' => count(array_filter($results, fn (StudentResult $result): bool => $result->isComplete())),
                'passed_all' => count(array_filter($results, fn (StudentResult $result): bool => $result->passedAll())),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Student $student, StudentResult $result, ?int $rank, ?GradeStudentNote $note): array
    {
        return [
            'uuid' => $student->uuid,
            'name' => $student->full_name,
            'number' => $student->student_number,
            'status' => $student->status?->value,
            'marks' => array_values(array_map(fn ($mark) => $mark?->toScalar(), $result->marks)),
            'cells' => array_values(array_map(fn (?array $cell): ?array => $cell === null ? null : [
                'percent' => $cell['percent'],
                'passed' => $cell['passed'],
                'band' => $cell['band']->value,
                'exempt' => $cell['exempt'],
            ], $result->cells)),
            'total' => $result->total,
            'maximum' => $result->maximum,
            'percentage' => $result->percentage,
            'below' => $result->belowPassMark,
            'absences' => $result->absences,
            'missing' => $result->missing,
            'complete' => $result->isComplete(),
            'passed' => $result->passedAll(),
            'rank' => $rank,
            'band' => $result->band?->value,
            'exempt_all' => $result->exemptAll,
            'behaviour' => $note?->behaviour?->value,
            'notes' => $note?->notes,
        ];
    }
}
