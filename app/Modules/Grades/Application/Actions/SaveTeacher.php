<?php

namespace App\Modules\Grades\Application\Actions;

use App\Models\User;
use App\Modules\Grades\Application\TeacherCodes;
use App\Modules\Grades\Models\GradeMark;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditLogger;
use App\Platform\Exceptions\DomainRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * Creates a teacher, changes a teacher's name, code or whether they may
 * sign in, or deletes one (ADR-0034). The owner sets the code (the
 * employee number); it is kept as a digest and encrypted (TeacherCodes),
 * never written to the audit trail. Changing the code or deactivating
 * raises the lock version, which ends the teacher's open sessions (G2).
 * Repeating the same change changes nothing and records nothing.
 */
class SaveTeacher
{
    public function __construct(private readonly AuditLogger $audit, private readonly TeacherCodes $codes) {}

    public function create(User $actor, string $name, string $code): Teacher
    {
        $name = $this->validName($name);
        $this->codes->assertAcceptable($code);

        return DB::transaction(function () use ($actor, $name, $code): Teacher {
            EnsureManager::check($actor);
            $digest = $this->freeDigest($code, null);

            $teacher = new Teacher;
            $teacher->forceFill([
                'name' => $name,
                'code_digest' => $digest,
                'code_encrypted' => $this->codes->encrypt($code),
                'is_active' => true,
            ])->save();

            $this->audit->record('grades.teacher_created', $teacher, ['name' => $name], actor: $actor);

            return $teacher;
        }, 3);
    }

    public function rename(User $actor, Teacher $teacher, string $name): Teacher
    {
        $name = $this->validName($name);

        return $this->change($actor, $teacher, function (Teacher $locked) use ($actor, $name): void {
            if ($locked->name === $name) {
                return;
            }

            $old = $locked->name;
            $locked->forceFill(['name' => $name])->save();
            $this->audit->record('grades.teacher_renamed', $locked, [], ['name' => ['from' => $old, 'to' => $name]], actor: $actor);
        });
    }

    public function changeCode(User $actor, Teacher $teacher, string $code): Teacher
    {
        $this->codes->assertAcceptable($code);

        return $this->change($actor, $teacher, function (Teacher $locked) use ($actor, $code): void {
            $digest = $this->freeDigest($code, $locked->id);

            if (hash_equals($locked->code_digest, $digest)) {
                // Same code: keep a copy if the teacher was saved before copies were kept.
                if ($locked->code_encrypted === null) {
                    $locked->forceFill(['code_encrypted' => $this->codes->encrypt($code)])->save();
                }

                return;
            }

            $locked->forceFill([
                'code_digest' => $digest,
                'code_encrypted' => $this->codes->encrypt($code),
                'lock_version' => $locked->lock_version + 1,
            ])->save();
            $this->audit->record('grades.teacher_code_changed', $locked, actor: $actor);
        });
    }

    public function setActive(User $actor, Teacher $teacher, bool $active): Teacher
    {
        return $this->change($actor, $teacher, function (Teacher $locked) use ($actor, $active): void {
            if ($locked->is_active === $active) {
                return;
            }

            $locked->forceFill(['is_active' => $active, 'lock_version' => $locked->lock_version + 1])->save();
            $this->audit->record($active ? 'grades.teacher_activated' : 'grades.teacher_deactivated', $locked, actor: $actor);
        });
    }

    /**
     * Deletes a teacher who has entered no marks; their subjects are left
     * without a teacher. A teacher with marks keeps them signed and can
     * only be stopped, so the marks always name who entered them.
     */
    public function delete(User $actor, Teacher $teacher): void
    {
        $this->change($actor, $teacher, function (Teacher $locked) use ($actor): void {
            if (GradeMark::query()->where('entered_by_teacher_id', $locked->id)->exists()) {
                throw new DomainRuleViolation(__('This teacher has entered marks and cannot be deleted. Stop their signing in instead.'), 'teacher');
            }

            $subjects = Subject::query()->where('teacher_id', $locked->id)->pluck('name')->all();
            Subject::query()->where('teacher_id', $locked->id)->update(['teacher_id' => null]);
            $this->audit->record('grades.teacher_deleted', $locked, ['name' => $locked->name, 'subjects' => $subjects], actor: $actor);
            $locked->delete();
        });
    }

    /**
     * @param  callable(Teacher): void  $apply
     */
    private function change(User $actor, Teacher $teacher, callable $apply): Teacher
    {
        return DB::transaction(function () use ($actor, $teacher, $apply): Teacher {
            $locked = Teacher::query()->whereKey($teacher->id)->lockForUpdate()->firstOrFail();
            EnsureManager::check($actor);
            $apply($locked);

            return $locked;
        }, 3);
    }

    private function validName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw new DomainRuleViolation(__('The name must be between :min and :max characters.', ['min' => 2, 'max' => 120]), 'name');
        }

        return $name;
    }

    /**
     * Two teachers never share a code: the digest is unique, and the owner
     * is told before the database refuses.
     */
    private function freeDigest(string $code, ?int $exceptTeacherId): string
    {
        $digest = $this->codes->digest($code);
        $taken = Teacher::query()
            ->where('code_digest', $digest)
            ->when($exceptTeacherId !== null, fn ($query) => $query->whereKeyNot($exceptTeacherId))
            ->exists();

        if ($taken) {
            throw new DomainRuleViolation(__('Another teacher already has this code.'), 'code');
        }

        return $digest;
    }
}
