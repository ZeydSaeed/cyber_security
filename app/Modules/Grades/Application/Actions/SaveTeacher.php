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
 * Creates a teacher, changes a teacher's name, specialty, code or whether
 * they may sign in, or deletes one (ADR-0034). Since 2026-11-16 the
 * application makes each code (TeacherCodes::generate) unless one is
 * given; it is kept as a digest and encrypted, never written to the audit
 * trail. Changing the code or deactivating
 * raises the lock version, which ends the teacher's open sessions (G2).
 * Repeating the same change changes nothing and records nothing.
 */
class SaveTeacher
{
    public function __construct(private readonly AuditLogger $audit, private readonly TeacherCodes $codes) {}

    /**
     * A new teacher; without a code, the application makes one.
     */
    public function create(User $actor, string $name, ?string $code = null, ?string $specialty = null): Teacher
    {
        $name = $this->validName($name);
        $specialty = $this->validSpecialty($specialty);
        if ($code !== null) {
            $this->codes->assertAcceptable($code);
        }

        return DB::transaction(function () use ($actor, $name, $code, $specialty): Teacher {
            EnsureManager::check($actor);
            [$code, $digest] = $code === null ? $this->newCode(null) : [$code, $this->freeDigest($code, null)];

            $teacher = new Teacher;
            $teacher->forceFill([
                'name' => $name,
                'specialty' => $specialty,
                'code_digest' => $digest,
                'code_encrypted' => $this->codes->encrypt($code),
                'is_active' => true,
            ])->save();

            $this->audit->record('grades.teacher_created', $teacher, ['name' => $name, 'specialty' => $specialty], actor: $actor);

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

    public function changeSpecialty(User $actor, Teacher $teacher, ?string $specialty): Teacher
    {
        $specialty = $this->validSpecialty($specialty);

        return $this->change($actor, $teacher, function (Teacher $locked) use ($actor, $specialty): void {
            if ($locked->specialty === $specialty) {
                return;
            }

            $old = $locked->specialty;
            $locked->forceFill(['specialty' => $specialty])->save();
            $this->audit->record('grades.teacher_specialty_changed', $locked, [], ['specialty' => ['from' => $old, 'to' => $specialty]], actor: $actor);
        });
    }

    /**
     * A new code made by the application; the old one stops working and
     * the teacher's open sessions end. Returns the teacher with the code.
     */
    public function regenerateCode(User $actor, Teacher $teacher): Teacher
    {
        return $this->change($actor, $teacher, function (Teacher $locked) use ($actor): void {
            [$code, $digest] = $this->newCode($locked->id);

            $locked->forceFill([
                'code_digest' => $digest,
                'code_encrypted' => $this->codes->encrypt($code),
                'lock_version' => $locked->lock_version + 1,
            ])->save();
            $this->audit->record('grades.teacher_code_changed', $locked, ['generated' => true], actor: $actor);
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

    /**
     * A made code no other teacher has (a clash is about one in 3.5 * 10^9
     * per pair: try again rather than fail).
     *
     * @return array{0: string, 1: string}
     */
    private function newCode(?int $exceptTeacherId): array
    {
        do {
            $code = $this->codes->generate();
            $digest = $this->codes->digest($code);
            $taken = Teacher::query()
                ->where('code_digest', $digest)
                ->when($exceptTeacherId !== null, fn ($query) => $query->whereKeyNot($exceptTeacherId))
                ->exists();
        } while ($taken);

        return [$code, $digest];
    }

    private function validSpecialty(?string $specialty): ?string
    {
        $specialty = $specialty === null ? '' : trim((string) preg_replace('/\s+/u', ' ', $specialty));

        if (mb_strlen($specialty) > 80) {
            throw new DomainRuleViolation(__('The specialty is longer than :max characters.', ['max' => 80]), 'specialty');
        }

        return $specialty === '' ? null : $specialty;
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
