<?php

namespace App\Modules\Grades\Application\Actions;

use App\Models\User;
use App\Modules\Grades\Application\TeacherCodes;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditLogger;
use App\Platform\Exceptions\DomainRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * Creates a teacher, or changes a teacher's name, code or whether they may
 * sign in (ADR-0034). The owner sets the code; it is kept only as a digest
 * and never written to the audit trail. Changing the code or deactivating
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
            $teacher->forceFill(['name' => $name, 'code_digest' => $digest, 'is_active' => true])->save();

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
                return;
            }

            $locked->forceFill(['code_digest' => $digest, 'lock_version' => $locked->lock_version + 1])->save();
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
