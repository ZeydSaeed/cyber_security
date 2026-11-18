<?php

namespace App\Modules\Grades\Database\Factories;

use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\SubjectTeacher;
use App\Modules\Grades\Models\Teacher;
use Illuminate\Database\Eloquent\Factories\Factory;
use WeakMap;

/**
 * A subject; by default one teacher teaches every section of its stage.
 * `teacher_id` is a factory input only (the table has no such column since
 * 2026-11-18): it becomes a SubjectTeacher row for each section.
 *
 * @extends Factory<Subject>
 */
class SubjectFactory extends Factory
{
    protected $model = Subject::class;

    /** @var WeakMap<Subject, int>|null The teacher each made subject is to get. */
    private static ?WeakMap $teachers = null;

    /**
     * @return WeakMap<Subject, int>
     */
    private static function teachers(): WeakMap
    {
        /** @var WeakMap<Subject, int> $map */
        $map = self::$teachers ??= new WeakMap;

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stage' => Stage::First,
            'name' => 'Subject '.fake()->unique()->numberBetween(1, 1_000_000),
            'teacher_id' => Teacher::factory(),
            'sort_order' => 0,
        ];
    }

    public function configure(): static
    {
        return $this
            ->afterMaking(function (Subject $subject): void {
                $teacher = $subject->getAttribute('teacher_id');
                unset($subject->teacher_id);
                if (is_int($teacher) || is_string($teacher)) {
                    self::teachers()->offsetSet($subject, (int) $teacher);
                }
            })
            ->afterCreating(function (Subject $subject): void {
                $teacher = self::teachers()->offsetExists($subject) ? self::teachers()->offsetGet($subject) : null;
                if ($teacher === null) {
                    return;
                }

                foreach ($subject->stage->sections() as $section) {
                    $row = new SubjectTeacher;
                    $row->forceFill(['subject_id' => $subject->id, 'section' => $section, 'teacher_id' => $teacher])->save();
                }
            });
    }

    public function stage(Stage $stage): static
    {
        return $this->state(fn (): array => ['stage' => $stage]);
    }

    public function unassigned(): static
    {
        return $this->state(fn (): array => ['teacher_id' => null]);
    }

    /**
     * The teacher of one section of the subject (null: none).
     */
    public static function teacherOf(Subject $subject, Section $section = Section::A): ?Teacher
    {
        return SubjectTeacher::query()->where('subject_id', $subject->id)->where('section', $section->value)->first()?->teacher;
    }
}
