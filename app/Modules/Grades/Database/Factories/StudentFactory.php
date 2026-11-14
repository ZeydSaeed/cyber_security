<?php

namespace App\Modules\Grades\Database\Factories;

use App\Modules\Grades\Domain\NameKey;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    protected $model = Student::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->name().' '.fake()->unique()->numberBetween(1, 1_000_000);

        return [
            'full_name' => $name,
            'name_key' => NameKey::of($name),
            'stage' => Stage::First,
            'section' => Section::A,
            'student_number' => null,
        ];
    }

    public function in(Stage $stage, Section $section): static
    {
        return $this->state(fn (): array => ['stage' => $stage, 'section' => $section]);
    }

    public function named(string $name): static
    {
        return $this->state(fn (): array => ['full_name' => NameKey::clean($name), 'name_key' => NameKey::of($name)]);
    }
}
