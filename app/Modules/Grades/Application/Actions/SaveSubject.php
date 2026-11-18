<?php

namespace App\Modules\Grades\Application\Actions;

use App\Models\User;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\TeachingKind;
use App\Modules\Grades\Models\GradeMark;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\SubjectTeacher;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditLogger;
use App\Platform\Exceptions\DomainRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * Creates a subject in a stage, changes its name, stage or the teacher of
 * a section, or deletes it (ADR-0034). Its marks belong to its stage: a subject with
 * marks keeps its stage and cannot be deleted (the database refuses both
 * too, through the marks' composite foreign key).
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
                'sort_order' => (int) Subject::query()->where('stage', $stage->value)->max('sort_order') + 1,
            ])->save();

            $this->audit->record('grades.subject_created', $subject, ['stage' => $stage->value, 'name' => $name, 'teacher' => $teacher?->name], actor: $actor);

            if ($teacher !== null) {
                foreach ($stage->sections() as $section) {
                    $this->place($subject, $section, $teacher);
                }
            }

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

    /**
     * Gives one section of the subject to the teacher, or every section of
     * its stage when no section is named; null leaves it without a teacher.
     * Each section that changes is audited on its own.
     */
    public function assign(User $actor, Subject $subject, ?Teacher $teacher, ?Section $section = null): Subject
    {
        return DB::transaction(function () use ($actor, $subject, $teacher, $section): Subject {
            $locked = Subject::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            EnsureManager::check($actor);

            if ($section !== null && ! $locked->stage->hasSection($section)) {
                throw new DomainRuleViolation(__('This stage has no such section.'), 'subjects');
            }

            $current = SubjectTeacher::query()->with('teacher:id,name')->where('subject_id', $locked->id)->get()
                ->keyBy(fn (SubjectTeacher $row): string => $row->section->value);

            foreach ($section === null ? $locked->stage->sections() : [$section] as $each) {
                $row = $current->get($each->value);

                if ($row?->teacher_id === $teacher?->id) {
                    continue;
                }

                $this->place($locked, $each, $teacher);
                $this->audit->record('grades.subject_assigned', $locked, ['section' => $each->value], ['teacher' => ['from' => $row?->teacher?->name, 'to' => $teacher?->name]], actor: $actor);
            }

            return $locked;
        }, 3);
    }

    public function changeStage(User $actor, Subject $subject, Stage $stage): Subject
    {
        return DB::transaction(function () use ($actor, $subject, $stage): Subject {
            $locked = Subject::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            EnsureManager::check($actor);

            if ($locked->stage === $stage) {
                return $locked;
            }

            if ($this->hasMarks($locked)) {
                throw new DomainRuleViolation(__('This subject already has marks, so its stage cannot change.'), 'stage');
            }

            $this->assertFreeName($stage, $locked->name, $locked->id);
            $old = $locked->stage->value;
            // A section the new stage does not have loses its teacher.
            SubjectTeacher::query()->where('subject_id', $locked->id)
                ->whereNotIn('section', array_map(fn (Section $section): string => $section->value, $stage->sections()))
                ->delete();
            $locked->forceFill([
                'stage' => $stage,
                'sort_order' => (int) Subject::query()->where('stage', $stage->value)->max('sort_order') + 1,
            ])->save();
            $this->audit->record('grades.subject_stage_changed', $locked, [], ['stage' => ['from' => $old, 'to' => $stage->value]], actor: $actor);

            return $locked;
        }, 3);
    }

    public function delete(User $actor, Subject $subject): void
    {
        DB::transaction(function () use ($actor, $subject): void {
            $locked = Subject::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            EnsureManager::check($actor);

            if ($this->hasMarks($locked)) {
                throw new DomainRuleViolation(__('This subject already has marks and cannot be deleted.'), 'subject');
            }

            $this->audit->record('grades.subject_deleted', $locked, ['stage' => $locked->stage->value, 'name' => $locked->name], actor: $actor);
            $locked->delete();
        }, 3);
    }

    private function place(Subject $subject, Section $section, ?Teacher $teacher): void
    {
        if ($teacher === null) {
            SubjectTeacher::query()->where('subject_id', $subject->id)->where('section', $section->value)->delete();

            return;
        }

        // A new teacher starts without the old one's type and assistant.
        $row = SubjectTeacher::query()->where('subject_id', $subject->id)->where('section', $section->value)->first() ?? new SubjectTeacher;
        $row->forceFill(['subject_id' => $subject->id, 'section' => $section, 'teacher_id' => $teacher->id, 'kind' => null, 'assistant_id' => null])->save();
    }

    /**
     * How the teacher teaches one section of the subject (owner's request,
     * 2026-11-18): theoretical or practical, and their assistant, another
     * teacher. Null clears either. The section must have a teacher.
     */
    public function describe(User $actor, Subject $subject, Section $section, ?TeachingKind $kind, ?Teacher $assistant): void
    {
        DB::transaction(function () use ($actor, $subject, $section, $kind, $assistant): void {
            $locked = Subject::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            EnsureManager::check($actor);

            $row = SubjectTeacher::query()->with('assistant:id,name')
                ->where('subject_id', $locked->id)->where('section', $section->value)->first();

            if ($row === null) {
                throw new DomainRuleViolation(__('This section has no teacher yet.'), 'subjects');
            }

            if ($assistant !== null && $assistant->id === $row->teacher_id) {
                throw new DomainRuleViolation(__('A teacher cannot be their own assistant.'), 'subjects');
            }

            $changes = [];
            if ($row->kind !== $kind) {
                $changes['kind'] = ['from' => $row->kind?->value, 'to' => $kind?->value];
            }
            if ($row->assistant_id !== $assistant?->id) {
                $changes['assistant'] = ['from' => $row->assistant?->name, 'to' => $assistant?->name];
            }

            if ($changes === []) {
                return;
            }

            $row->forceFill(['kind' => $kind, 'assistant_id' => $assistant?->id])->save();
            $this->audit->record('grades.subject_teaching_changed', $locked, ['section' => $section->value], $changes, actor: $actor);
        }, 3);
    }

    private function hasMarks(Subject $subject): bool
    {
        return GradeMark::query()->where('subject_id', $subject->id)->exists();
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
