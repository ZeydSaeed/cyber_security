<?php

use App\Modules\Grades\Contracts\GradesOverview;
use App\Modules\Grades\Database\Factories\SubjectFactory;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Models\GradeMark;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Grades\GradesFixtures;

/*
| The dashboard's view of mark entry (ADR-0034), through the module's
| contract: a fixed, small number of queries however large the department.
*/

uses(RefreshDatabase::class);

it('counts entered and expected marks, students, subjects and active teachers', function () {
    $subjects = Subject::factory()->count(2)->create();
    Subject::factory()->stage(Stage::Second)->unassigned()->create();
    Teacher::factory()->inactive()->create();
    $first = Student::factory()->count(4)->create();
    Student::factory()->in(Stage::Second, Section::A)->count(3)->create();
    GradesFixtures::openWindow();
    (new GradeMark)->forceFill([
        'student_id' => $first[0]->id, 'subject_id' => $subjects[0]->id, 'stage' => Stage::First,
        'period' => Period::Month1, 'mark' => 90, 'entered_by_teacher_id' => SubjectFactory::teacherOf($subjects[0])?->id,
    ])->save();

    expect(app(GradesOverview::class)->progress())->toBe([
        'period' => 'month_1',
        'open' => true,
        'entered' => 1,
        // 4 students x 2 subjects + 3 students x 1 subject.
        'expected' => 11,
        'students' => 7,
        'subjects' => 3,
        'teachers' => 2,
    ]);
});

it('runs the same few queries for a large department as for an empty one', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(GradesOverview::class)->progress();

        return count(DB::getQueryLog());
    };

    $empty = $count();
    Subject::factory()->count(8)->create();
    Student::factory()->count(60)->create();

    expect($count())->toBe($empty)->toBeLessThanOrEqual(5);
});
