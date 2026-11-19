<?php

namespace App\Modules\Grades\Application\Actions;

use App\Models\User;
use App\Modules\Grades\Domain\Behaviour;
use App\Modules\Grades\Domain\GradeBands;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\ResultDisplay;
use App\Modules\Grades\Domain\TeacherResults;
use App\Modules\Grades\Models\GradeSettings;
use App\Modules\Grades\Models\GradeStudentNote;
use App\Modules\Grades\Models\Student;
use App\Platform\Audit\AuditLogger;
use App\Platform\Exceptions\DomainRuleViolation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * What a result says and shows (ADR-0039): the department's pass and
 * exemption percentages, grade bands, the parts of a result card, the
 * tones of marks, and each student's behaviour and notes. In one
 * transaction each, grades.manage checked inside, audited with what
 * changed.
 */
class ManageResults
{
    public const MAX_NOTES = 1000;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $bands  band => lowest percentage
     * @param  array<string, bool>  $options  option => shown
     * @param  array<string, string>  $tones  what => tone
     */
    public function settings(User $actor, int $passPercent, ?int $exemptSubject, ?int $exemptOverall, array $bands, array $options, array $tones, ?int $appealDays = null, ?int $absenceFlag = null): GradeSettings
    {
        if ($appealDays !== null && ($appealDays < 1 || $appealDays > 90)) {
            throw new DomainRuleViolation(__('Give a number of days from 1 to 90.'), 'appeal_days');
        }
        if ($absenceFlag !== null && ($absenceFlag < 1 || $absenceFlag > 50)) {
            throw new DomainRuleViolation(__('Give a number of subjects from 1 to 50.'), 'absence_flag');
        }

        foreach (['pass_percent' => $passPercent, 'exempt_subject_percent' => $exemptSubject, 'exempt_overall_percent' => $exemptOverall] as $field => $percent) {
            if ($percent !== null && ($percent < 1 || $percent > 100)) {
                throw new DomainRuleViolation(__('Give a percentage from 1 to 100.'), $field);
            }
        }
        foreach (['exempt_subject_percent' => $exemptSubject, 'exempt_overall_percent' => $exemptOverall] as $field => $percent) {
            if ($percent !== null && $percent < $passPercent) {
                throw new DomainRuleViolation(__('An exemption cannot be below the pass percentage.'), $field);
            }
        }

        try {
            $bands = GradeBands::of($bands)->minimums;
        } catch (InvalidArgumentException) {
            throw new DomainRuleViolation(__('Each grade starts below the one above it, from 100 down to 0.'), 'bands');
        }

        $values = [
            'pass_percent' => $passPercent,
            'exempt_subject_percent' => $exemptSubject,
            'exempt_overall_percent' => $exemptOverall,
            'grade_bands' => $bands,
            'result_options' => ResultDisplay::options($options),
            'mark_tones' => ResultDisplay::tones($tones),
            // Appeals and the absence flag (ADR-0042).
            'appeal_days' => $appealDays,
            'absence_flag' => $absenceFlag,
        ];

        return DB::transaction(function () use ($actor, $values): GradeSettings {
            GradeSettings::current();
            $settings = GradeSettings::query()->whereKey(GradeSettings::ROW)->lockForUpdate()->firstOrFail();
            EnsureManager::check($actor);

            $before = [
                'pass_percent' => $settings->pass_percent,
                'exempt_subject_percent' => $settings->exempt_subject_percent,
                'exempt_overall_percent' => $settings->exempt_overall_percent,
                'grade_bands' => $settings->bands()->minimums,
                'result_options' => $settings->options(),
                'mark_tones' => $settings->tones(),
                'appeal_days' => $settings->appeal_days,
                'absence_flag' => $settings->absence_flag,
            ];
            $changes = [];
            foreach ($values as $field => $value) {
                if ($before[$field] !== $value) {
                    $changes[$field] = ['from' => $before[$field], 'to' => $value];
                }
            }

            if ($changes === []) {
                return $settings;
            }

            $settings->forceFill($values)->save();
            $this->audit->record('grades.result_settings_changed', $settings, [], $changes, actor: $actor);

            return $settings;
        }, 3);
    }

    /**
     * When teachers may read their own students' results, and what they
     * see of them (ADR-0040).
     *
     * @param  array<string, bool>  $options
     */
    public function teacherView(User $actor, TeacherResults $access, array $options): GradeSettings
    {
        $options = ResultDisplay::teacherOptions($options);

        return DB::transaction(function () use ($actor, $access, $options): GradeSettings {
            GradeSettings::current();
            $settings = GradeSettings::query()->whereKey(GradeSettings::ROW)->lockForUpdate()->firstOrFail();
            EnsureManager::check($actor);

            $changes = array_filter([
                'teacher_results' => ['from' => $settings->teacher_results->value, 'to' => $access->value],
                'teacher_view' => ['from' => $settings->teacherView(), 'to' => $options],
            ], fn (array $change): bool => $change['from'] !== $change['to']);

            if ($changes === []) {
                return $settings;
            }

            $settings->forceFill(['teacher_results' => $access, 'teacher_view' => $options])->save();
            $this->audit->record('grades.teacher_view_changed', $settings, [], $changes, actor: $actor);

            return $settings;
        }, 3);
    }

    public function note(User $actor, Student $student, Period $period, ?Behaviour $behaviour, ?string $notes): void
    {
        $notes = $notes === null ? null : trim($notes);
        $notes = $notes === '' ? null : $notes;
        if ($notes !== null && mb_strlen($notes) > self::MAX_NOTES) {
            throw new DomainRuleViolation(__('The notes are too long.'), 'notes');
        }

        DB::transaction(function () use ($actor, $student, $period, $behaviour, $notes): void {
            EnsureManager::check($actor);

            $row = GradeStudentNote::query()
                ->where('student_id', $student->id)
                ->where('period', $period->value)
                ->lockForUpdate()
                ->first() ?? (new GradeStudentNote)->forceFill(['student_id' => $student->id, 'period' => $period]);

            $changes = array_filter([
                'behaviour' => ['from' => $row->behaviour?->value, 'to' => $behaviour?->value],
                'notes' => ['from' => $row->notes, 'to' => $notes],
            ], fn (array $change): bool => $change['from'] !== $change['to']);

            if ($changes === []) {
                return;
            }

            $row->forceFill(['behaviour' => $behaviour, 'notes' => $notes, 'updated_by_user_id' => $actor->id])->save();
            $this->audit->record('grades.student_note_saved', $student, ['period' => $period->value], $changes, actor: $actor);
        }, 3);
    }
}
