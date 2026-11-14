<?php

namespace App\Modules\Grades\Database\Factories;

use App\Modules\Grades\Application\TeacherCodes;
use App\Modules\Grades\Models\Teacher;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Teacher>
 */
class TeacherFactory extends Factory
{
    protected $model = Teacher::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'code_digest' => app(TeacherCodes::class)->digest(Str::random(16)),
            'is_active' => true,
        ];
    }

    public function withCode(string $code): static
    {
        return $this->state(fn (): array => ['code_digest' => app(TeacherCodes::class)->digest($code)]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
