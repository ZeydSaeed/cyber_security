<?php

namespace App\Modules\Grades\Database\Factories;

use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subject>
 */
class SubjectFactory extends Factory
{
    protected $model = Subject::class;

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

    public function stage(Stage $stage): static
    {
        return $this->state(fn (): array => ['stage' => $stage]);
    }

    public function unassigned(): static
    {
        return $this->state(fn (): array => ['teacher_id' => null]);
    }
}
