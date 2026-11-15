<?php

use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Models\GradeMark;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;
use Database\Seeders\GradesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Grades\GradesFixtures;

/*
| The owner's demo department (GradesDemoSeeder): made through the actions,
| only on an empty department, never in production.
*/

uses(RefreshDatabase::class);

it('makes five teachers, five subjects per stage, students with codes, marks, and published results', function () {
    GradesFixtures::owner();

    $this->seed(GradesDemoSeeder::class);

    expect(Teacher::query()->count())->toBe(5)
        ->and(Subject::query()->where('stage', 1)->count())->toBe(5)
        ->and(Subject::query()->where('stage', 2)->count())->toBe(5)
        ->and(Student::query()->where('stage', 1)->where('section', 'A')->count())->toBe(12)
        ->and(Student::query()->where('stage', 2)->where('section', 'A')->count())->toBe(10)
        ->and(Student::query()->where('stage', 2)->where('section', 'B')->count())->toBe(10)
        ->and(Student::query()->whereNull('code_digest')->count())->toBe(0)
        // 32 students x 5 subjects, less two students in the last subject of each group.
        ->and(GradeMark::query()->count())->toBe(32 * 5 - 2 * 3)
        ->and(GradeMark::query()->where('is_absent', true)->count())->toBeGreaterThan(0)
        ->and(GradePeriod::for(Period::Month1)->isPublished())->toBeTrue()
        ->and(file_exists(storage_path('app/private/demo/credentials.txt')))->toBeTrue();
});

it('adds itself next to existing data, gives everyone a code, and runs only once', function () {
    GradesFixtures::owner();
    $mine = Student::factory()->create();

    $this->seed(GradesDemoSeeder::class);

    expect($mine->fresh()?->code_digest)->not->toBeNull()
        ->and(fn () => $this->seed(GradesDemoSeeder::class))->toThrow(RuntimeException::class, 'has already run here');
});

it('never runs in production', function () {
    GradesFixtures::owner();

    app()->detectEnvironment(fn (): string => 'production');
    expect(fn () => app()->call([new GradesDemoSeeder, 'run']))->toThrow(RuntimeException::class, 'never runs in production');
});
