<?php

namespace App\Modules\Grades\Application\Actions;

use App\Models\User;
use App\Modules\Grades\Domain\EditRequestStatus;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\StudentAccess;
use App\Modules\Grades\Domain\TeacherAccess;
use App\Modules\Grades\Models\GradeEditRequest;
use App\Modules\Grades\Models\GradeSettings;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\SubjectTeacher;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditLogger;
use App\Platform\Exceptions\DomainRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * The owner's control of who may sign in and change marks (ADR-0037).
 * Each change runs in one transaction, re-checks grades.manage inside it,
 * locks the row it changes, and is audited with what changed (from, to).
 * Blocking a teacher raises their lock version, which ends their open
 * sessions at once.
 */
class ManageAccess
{
    /** The longest editing time or grant: a year, in hours. */
    public const MAX_HOURS = 8760;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{
     *     teacher_attempts_per_minute: int, teacher_attempts_per_hour: int,
     *     student_attempts_per_minute: int, student_attempts_per_hour: int,
     *     teacher_max_logins: int|null, student_max_logins: int|null,
     *     teacher_mode: TeacherAccess, teacher_edit_hours: int|null,
     *     student_access: StudentAccess
     * }  $values
     */
    public function settings(User $actor, array $values): GradeSettings
    {
        if ($values['teacher_mode'] === TeacherAccess::Timed && $values['teacher_edit_hours'] === null) {
            throw new DomainRuleViolation(__('Give the editing time in hours for the timed mode.'), 'teacher_edit_hours');
        }

        return DB::transaction(function () use ($actor, $values): GradeSettings {
            GradeSettings::current();
            $settings = GradeSettings::query()->whereKey(GradeSettings::ROW)->lockForUpdate()->firstOrFail();
            EnsureManager::check($actor);

            $changes = [];
            foreach ($values as $field => $value) {
                $old = $settings->getAttribute($field);
                $plain = fn (mixed $v): mixed => $v instanceof \BackedEnum ? $v->value : $v;
                if ($plain($old) !== $plain($value)) {
                    $changes[$field] = ['from' => $plain($old), 'to' => $plain($value)];
                }
            }

            if ($changes === []) {
                return $settings;
            }

            $settings->forceFill($values)->save();
            $this->audit->record('grades.access_settings_changed', $settings, [], $changes, actor: $actor);

            return $settings;
        }, 3);
    }

    /**
     * A teacher's own access: a mode (null: the department's), editing hours
     * (null: the department's), and the reason for a lock.
     */
    public function teacher(User $actor, Teacher $teacher, ?TeacherAccess $mode, ?int $hours, ?string $reason): Teacher
    {
        $this->assertHours($hours, 'edit_hours');
        $reason = $this->validReason($reason);

        return DB::transaction(function () use ($actor, $teacher, $mode, $hours, $reason): Teacher {
            $locked = Teacher::query()->whereKey($teacher->id)->lockForUpdate()->firstOrFail();
            EnsureManager::check($actor);

            $changes = array_filter([
                'access_mode' => ['from' => $locked->access_mode?->value, 'to' => $mode?->value],
                'edit_hours' => ['from' => $locked->edit_hours, 'to' => $hours],
                'access_reason' => ['from' => $locked->access_reason, 'to' => $reason],
            ], fn (array $change): bool => $change['from'] !== $change['to']);

            if ($changes === []) {
                return $locked;
            }

            $settings = GradeSettings::current();
            $wasBlocked = $locked->accessMode($settings) === TeacherAccess::Blocked;
            $locked->forceFill(['access_mode' => $mode, 'edit_hours' => $hours, 'access_reason' => $reason]);
            if (! $wasBlocked && $locked->accessMode($settings) === TeacherAccess::Blocked) {
                $locked->lock_version++;
            }
            $locked->save();

            $this->audit->record('grades.teacher_access_changed', $locked, ['teacher_name' => $locked->name], $changes, actor: $actor);

            return $locked;
        }, 3);
    }

    /**
     * One section of a subject: a mode for its teacher there (null: the
     * teacher's), the reason, and a grant to edit for some hours from now
     * (null: unchanged; 0: withdrawn).
     */
    public function section(User $actor, Subject $subject, Section $section, ?TeacherAccess $mode, ?string $reason, ?int $grantHours): SubjectTeacher
    {
        if ($grantHours !== null && $grantHours !== 0) {
            $this->assertHours($grantHours, 'grant_hours');
        }
        $reason = $this->validReason($reason);

        return DB::transaction(function () use ($actor, $subject, $section, $mode, $reason, $grantHours): SubjectTeacher {
            $row = SubjectTeacher::query()
                ->where('subject_id', $subject->id)
                ->where('section', $section->value)
                ->lockForUpdate()
                ->first();
            EnsureManager::check($actor);

            if ($row === null) {
                throw new DomainRuleViolation(__('This section has no teacher yet.'), 'section');
            }

            $until = match (true) {
                $grantHours === null => $row->edit_until,
                $grantHours === 0 => null,
                default => now()->addHours($grantHours)->toImmutable(),
            };

            $changes = array_filter([
                'access_mode' => ['from' => $row->access_mode?->value, 'to' => $mode?->value],
                'access_reason' => ['from' => $row->access_reason, 'to' => $reason],
                'edit_until' => ['from' => $row->edit_until?->toIso8601String(), 'to' => $until?->toIso8601String()],
            ], fn (array $change): bool => $change['from'] !== $change['to']);

            if ($changes === []) {
                return $row;
            }

            $row->forceFill(['access_mode' => $mode, 'access_reason' => $reason, 'edit_until' => $until])->save();
            $this->audit->record('grades.section_access_changed', $subject, ['section' => $section->value, 'teacher' => $row->teacher_id], $changes, actor: $actor);

            return $row;
        }, 3);
    }

    public function resetTeacherLogins(User $actor, Teacher $teacher): void
    {
        DB::transaction(function () use ($actor, $teacher): void {
            $locked = Teacher::query()->whereKey($teacher->id)->lockForUpdate()->firstOrFail();
            EnsureManager::check($actor);

            if ($locked->login_count === 0) {
                return;
            }

            $this->audit->record('grades.teacher_logins_reset', $locked, [], ['login_count' => ['from' => $locked->login_count, 'to' => 0]], actor: $actor);
            $locked->forceFill(['login_count' => 0])->save();
        }, 3);
    }

    /** Every student's count back to 0 (a new period, say). Returns how many changed. */
    public function resetStudentLogins(User $actor): int
    {
        return DB::transaction(function () use ($actor): int {
            EnsureManager::check($actor);
            $count = Student::query()->where('login_count', '>', 0)->update(['login_count' => 0]);

            if ($count > 0) {
                $this->audit->record('grades.student_logins_reset', null, ['students' => $count], actor: $actor);
            }

            return $count;
        }, 3);
    }

    public function student(User $actor, Student $student, bool $blocked): Student
    {
        return DB::transaction(function () use ($actor, $student, $blocked): Student {
            $locked = Student::query()->whereKey($student->id)->lockForUpdate()->firstOrFail();
            EnsureManager::check($actor);

            if ($locked->access_blocked === $blocked) {
                return $locked;
            }

            $locked->forceFill(['access_blocked' => $blocked])->save();
            $this->audit->record($blocked ? 'grades.student_blocked' : 'grades.student_unblocked', $locked, actor: $actor);

            return $locked;
        }, 3);
    }

    /**
     * Approves a pending request (the teacher's section may be edited for
     * the given hours, from now) or rejects it. A request is decided once.
     */
    public function decide(User $actor, GradeEditRequest $request, bool $approve, ?int $hours): GradeEditRequest
    {
        if ($approve) {
            $this->assertHours($hours, 'hours');
        }

        return DB::transaction(function () use ($actor, $request, $approve, $hours): GradeEditRequest {
            $locked = GradeEditRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            EnsureManager::check($actor);

            $next = $approve ? EditRequestStatus::Approved : EditRequestStatus::Rejected;
            if (! $locked->status->canBecome($next)) {
                throw new DomainRuleViolation(__('This request has already been answered.'), 'request');
            }

            if ($approve) {
                $row = SubjectTeacher::query()
                    ->where('subject_id', $locked->subject_id)
                    ->where('section', $locked->section->value)
                    ->lockForUpdate()
                    ->first();

                if ($row === null || $row->teacher_id !== $locked->teacher_id) {
                    throw new DomainRuleViolation(__('This teacher no longer teaches this section.'), 'request');
                }

                $until = now()->addHours((int) $hours)->toImmutable();
                // A longer grant already given is kept.
                if ($row->edit_until === null || $row->edit_until->lessThan($until)) {
                    $row->forceFill(['edit_until' => $until])->save();
                }
            }

            $locked->forceFill([
                'status' => $next,
                'granted_hours' => $approve ? $hours : null,
                'decided_by_user_id' => $actor->id,
                'decided_at' => now(),
            ])->save();

            $this->audit->record('grades.edit_request_decided', $locked, [
                'decision' => $next->value,
                'hours' => $approve ? $hours : null,
                'section' => $locked->section->value,
            ], actor: $actor);

            return $locked;
        }, 3);
    }

    private function assertHours(?int $hours, string $field): void
    {
        if ($hours !== null && ($hours < 1 || $hours > self::MAX_HOURS)) {
            throw new DomainRuleViolation(__('Give a number of hours from 1 to :max.', ['max' => self::MAX_HOURS]), $field);
        }
    }

    private function validReason(?string $reason): ?string
    {
        $reason = $reason === null ? null : trim($reason);

        if ($reason !== null && mb_strlen($reason) > 255) {
            throw new DomainRuleViolation(__('The reason is too long.'), 'reason');
        }

        return $reason === '' ? null : $reason;
    }
}
