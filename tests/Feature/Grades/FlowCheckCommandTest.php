<?php

use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Models\GradeAppeal;
use App\Modules\Grades\Models\GradeMark;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\SubjectTeacher;
use App\Modules\Grades\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Grades\GradesFixtures;

/*
| The whole data flow, setting by setting (grades:flow-check, owner's
| request of 2026-11-20), on a small department: every check agrees, and
| without --keep the database is as it was.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    GradesFixtures::owner();
    $huda = Teacher::factory()->create(['name' => 'Huda Karim']);
    $omar = Teacher::factory()->create(['name' => 'Omar Salim']);
    $sara = Teacher::factory()->create(['name' => 'Sara Adel']);

    Subject::factory()->stage(Stage::First)->create(['name' => 'Basics', 'teacher_id' => $huda->id]);
    Subject::factory()->stage(Stage::First)->create(['name' => 'English', 'teacher_id' => $sara->id, 'max_mark' => 50]);
    $networks = Subject::factory()->stage(Stage::Second)->create(['name' => 'Networks', 'teacher_id' => $omar->id]);
    // Section A of Networks is Huda's; B stays Omar's.
    SubjectTeacher::query()->where('subject_id', $networks->id)->where('section', 'A')->update(['teacher_id' => $huda->id]);
    Subject::factory()->stage(Stage::Second)->create(['name' => 'Crypto', 'teacher_id' => $omar->id]);

    foreach ([[Stage::First, Section::A, 4], [Stage::Second, Section::A, 3], [Stage::Second, Section::B, 3]] as [$stage, $section, $count]) {
        foreach (range(1, $count) as $n) {
            Student::factory()->named("Student {$stage->value}{$section->value} {$n}")->in($stage, $section)->create();
        }
    }
});

it('passes every check and leaves the database as it was', function () {
    $this->artisan('grades:flow-check')
        ->expectsOutputToContain('checks passed.')
        ->expectsOutputToContain('Rolled back')
        ->assertExitCode(0);

    expect(GradeMark::query()->count())->toBe(0)
        ->and(GradeAppeal::query()->count())->toBe(0)
        ->and(GradePeriod::for(Period::Month1)->isPublished())->toBeFalse();
});

it('keeps the filled department with --keep', function () {
    $this->artisan('grades:flow-check --keep')->assertExitCode(0);

    // 4 students × 2 subjects + 6 students × 2 subjects.
    expect(GradeMark::query()->count())->toBe(20)
        ->and(GradeAppeal::query()->count())->toBeGreaterThan(0)
        ->and(GradePeriod::for(Period::Month1)->isPublished())->toBeTrue();
});
