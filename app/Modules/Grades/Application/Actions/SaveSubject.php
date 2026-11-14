<?php

namespace App\Modules\Grades\Application\Actions;

use App\Models\User;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditLogger;
use App\Platform\Exceptions\DomainRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * Creates a subject in a stage, or changes its name or teacher (ADR-0034).
 * A subject's stage never changes: its marks belong to that stage.
 */
class SaveSubject
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(User $actor, Stage $stage, string $name, ?Teacher $teacher = null): Subject
    {
        $name = $this->validName($name);

        return DB::transaction(function () use ($actor, $stage, $name, $teacher): Subject {
            EnsureManager::check($actor);
            $this->assertFreeName($stage, $name, null);

            $subject = new Subject;
            $subject->forceFill([
                'stage' => $stage,
                'name' => $name,
                'teacher_id' => $teacher?->id,
                'sort_order' => (int) Subject::query()->where('stage', $stage->value)->max('sort_order') + 1,
            ])->save();

            $this->audit->record('grades.subject_created', $subject, ['stage' => $stage->value, 'name' => $name, 'teacher' => $teacher?->name], actor: $actor);

            return $subject;
        }, 3);
    }

    public function rename(User $actor, Subject $subject, string $name): Subject
    {
        $name = $this->validName($name);

        return DB::transaction(function () use ($actor, $subject, $name): Subject {
            $locked = Subject::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            EnsureManager::check($actor);

            if ($locked->name !== $name) {
                $this->assertFreeName($locked->stage, $name, $locked->id);
                $old = $locked->name;
                $locked->forceFill(['name' => $name])->save();
                $this->audit->record('grades.subject_renamed', $locked, [], ['name' => ['from' => $old, 'to' => $name]], actor: $actor);
            }

            return $locked;
        }, 3);
    }

    public function assign(User $actor, Subject $subject, ?Teacher $teacher): Subject
    {
        return DB::transaction(function () use ($actor, $subject, $teacher): Subject {
            $locked = Subject::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            EnsureManager::check($actor);

            if ($locked->teacher_id !== $teacher?->id) {
                $old = $locked->teacher_id === null ? null : Teacher::query()->whereKey($locked->teacher_id)->value('name');
                $locked->forceFill(['teacher_id' => $teacher?->id])->save();
                $this->audit->record('grades.subject_assigned', $locked, [], ['teacher' => ['from' => $old, 'to' => $teacher?->name]], actor: $actor);
            }

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

    private function assertFreeName(Stage $stage, string $name, ?int $exceptId): void
    {
        $taken = Subject::query()
            ->where('stage', $stage->value)
            ->where('name', $name)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($taken) {
            throw new DomainRuleViolation(__('This stage already has a subject with this name.'), 'name');
        }
    }
}
