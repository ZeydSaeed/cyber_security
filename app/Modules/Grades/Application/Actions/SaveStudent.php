<?php

namespace App\Modules\Grades\Application\Actions;

use App\Models\User;
use App\Modules\Grades\Application\StudentCodes;
use App\Modules\Grades\Domain\NameKey;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Models\GradeMark;
use App\Modules\Grades\Models\Student;
use App\Platform\Audit\AuditLogger;
use App\Platform\Exceptions\DomainRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * One student added, changed or deleted by hand (owner's request,
 * 2026-11-16), with the import's rules (StudentImportPlanner): a name of 3
 * to 160 characters, the first stage in section A only, and a name unique
 * in its stage among students without a number. A new student gets a code
 * made by the application at once; the owner may change it to one they
 * type (StudentCodes::assertAcceptable), which ends the student's open
 * sessions. A student with marks keeps their stage and cannot be deleted:
 * the marks belong to that stage (the database refuses both too). The
 * student number, if any, is left to the import.
 */
class SaveStudent
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly IssueStudentCodes $issue,
        private readonly StudentCodes $codes,
    ) {}

    public function create(User $actor, string $name, Stage $stage, ?Section $section): Student
    {
        [$name, $key, $section] = $this->valid($name, $stage, $section);

        return DB::transaction(function () use ($actor, $name, $key, $stage, $section): Student {
            EnsureManager::check($actor);
            $this->assertFreeName($stage, $key, null);

            $student = new Student;
            $student->forceFill([
                'full_name' => $name,
                'name_key' => $key,
                'stage' => $stage,
                'section' => $section,
            ])->save();
            $this->issue->give($student);

            $this->audit->record('grades.student_created', $student, [
                'name' => $name, 'stage' => $stage->value, 'section' => $section->value,
            ], actor: $actor);

            return $student;
        }, 3);
    }

    /**
     * Changes the name, stage and section, and the code when one is given
     * (null or the same code changes nothing).
     */
    public function update(User $actor, Student $student, string $name, Stage $stage, ?Section $section, ?string $code = null): Student
    {
        [$name, $key, $section] = $this->valid($name, $stage, $section);
        $code = $code === null || trim($code) === '' ? null : $code;
        if ($code !== null) {
            $this->codes->assertAcceptable($code);
        }

        return DB::transaction(function () use ($actor, $student, $name, $key, $stage, $section, $code): Student {
            $locked = Student::query()->whereKey($student->id)->lockForUpdate()->firstOrFail();
            EnsureManager::check($actor);

            $digest = $code === null ? null : $this->codes->digest($code);
            $newCode = $digest !== null && ($locked->code_digest === null || ! hash_equals($locked->code_digest, $digest));

            $changes = array_filter([
                'full_name' => $locked->full_name !== $name ? ['from' => $locked->full_name, 'to' => $name] : null,
                'stage' => $locked->stage !== $stage ? ['from' => $locked->stage->value, 'to' => $stage->value] : null,
                'section' => $locked->section !== $section ? ['from' => $locked->section->value, 'to' => $section->value] : null,
            ]);

            if ($changes === [] && ! $newCode) {
                return $locked;
            }

            if (isset($changes['stage']) && $this->hasMarks($locked)) {
                throw new DomainRuleViolation(__('This student already has marks in the :stage stage and cannot change stage.', ['stage' => $locked->stage->name()]), 'stage');
            }

            if ($locked->student_number === null) {
                $this->assertFreeName($stage, $key, $locked->id);
            }

            $fields = ['full_name' => $name, 'name_key' => $key, 'stage' => $stage, 'section' => $section];

            if ($newCode) {
                if (Student::query()->where('code_digest', $digest)->whereKeyNot($locked->id)->exists()) {
                    throw new DomainRuleViolation(__('Another student already has this code.'), 'code');
                }
                $fields['code_digest'] = $digest;
                $fields['code_encrypted'] = $this->codes->encrypt((string) $code);
            }

            $locked->forceFill($fields)->save();

            if ($changes !== []) {
                $this->audit->record('grades.student_updated', $locked, [], $changes, actor: $actor);
            }
            if ($newCode) {
                // The code itself is never written to the audit trail.
                $this->audit->record('grades.student_code_replaced', $locked, ['typed' => true], actor: $actor);
            }

            return $locked;
        }, 3);
    }

    public function delete(User $actor, Student $student): void
    {
        DB::transaction(function () use ($actor, $student): void {
            $locked = Student::query()->whereKey($student->id)->lockForUpdate()->firstOrFail();
            EnsureManager::check($actor);

            if ($this->hasMarks($locked)) {
                throw new DomainRuleViolation(__('This student already has marks and cannot be deleted.'), 'student');
            }

            $this->audit->record('grades.student_deleted', $locked, [
                'name' => $locked->full_name, 'stage' => $locked->stage->value, 'section' => $locked->section->value,
            ], actor: $actor);
            $locked->delete();
        }, 3);
    }

    /**
     * @return array{0: string, 1: string, 2: Section}
     */
    private function valid(string $name, Stage $stage, ?Section $section): array
    {
        $name = NameKey::clean($name);
        $key = NameKey::of($name);
        // The first stage is one group: its students are in its one section.
        $section = $stage->isDivided() ? $section : $stage->sections()[0];

        $problem = match (true) {
            mb_strlen($name) < 3 || mb_strlen($key) < 3 => ['name', __('The name is missing or too short.')],
            mb_strlen($name) > 160 => ['name', __('The name is longer than :max characters.', ['max' => 160])],
            $section === null || ! $stage->hasSection($section) => ['section', __('The section must be A or B.')],
            default => null,
        };

        if ($problem !== null) {
            throw new DomainRuleViolation($problem[1], $problem[0]);
        }

        /** @var Section $section */
        return [$name, $key, $section];
    }

    /**
     * Without a student number, a name is unique in its stage (the import
     * matches on it; the database has the same partial unique index).
     */
    private function assertFreeName(Stage $stage, string $key, ?int $exceptId): void
    {
        $taken = Student::query()
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->where('stage', $stage->value)->where('name_key', $key)->whereNull('student_number')
            ->exists();

        if ($taken) {
            throw new DomainRuleViolation(__('Another student in this stage has this name.'), 'name');
        }
    }

    private function hasMarks(Student $student): bool
    {
        return GradeMark::query()->where('student_id', $student->id)->exists();
    }
}
