<?php

use App\Models\User;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| The database keeps the Grades rules even past the actions (ADR-0034):
| bad rows written by hand are refused. PostgreSQL only.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL only.');
    }

    $this->subject = Subject::factory()->create();
    $this->student = Student::factory()->create();
    DB::table('grade_periods')->insert(['period' => 'month_1', 'status' => 'open', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function insertMark(object $test, array $overrides = []): void
{
    inSavepoint(fn () => DB::table('grade_marks')->insert([
        'student_id' => $test->student->id,
        'subject_id' => $test->subject->id,
        'stage' => 1,
        'period' => 'month_1',
        'mark' => 70,
        'is_absent' => false,
        'entered_by_teacher_id' => $test->subject->teacher_id,
        'entered_by_user_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]));
}

/**
 * Runs a statement in its own savepoint: after a refused statement
 * PostgreSQL aborts the whole transaction, and the next statement of the
 * test would fail for that reason instead of its own.
 */
function inSavepoint(Closure $statement): mixed
{
    return DB::transaction($statement);
}

it('accepts a valid mark and an absence', function () {
    insertMark($this);
    DB::table('grade_marks')->delete();
    insertMark($this, ['mark' => null, 'is_absent' => true]);

    expect(DB::table('grade_marks')->count())->toBe(1);
});

it('refuses marks the rules forbid', function (array $overrides, string $constraint) {
    expect(fn () => insertMark($this, $overrides))->toThrow(QueryException::class, $constraint);
})->with([
    'above 100' => [['mark' => 101], 'grade_marks_mark_check'],
    'absent with a mark' => [['is_absent' => true], 'grade_marks_mark_check'],
    'present without a mark' => [['mark' => null], 'grade_marks_mark_check'],
    'no author' => [['entered_by_teacher_id' => null], 'grade_marks_author_check'],
    'unknown period' => [['period' => 'month_9'], 'grade_marks_period_foreign'],
    'stage 4' => [['stage' => 4], 'grade_marks_stage_check'],
]);

it('refuses two authors at once', function () {
    $user = User::factory()->create();

    expect(fn () => insertMark($this, ['entered_by_user_id' => $user->id]))->toThrow(QueryException::class, 'grade_marks_author_check');
});

it('refuses a mark of a subject of the other stage, even with a matching stage column', function () {
    $second = Subject::factory()->stage(Stage::Second)->create();

    // The student is in stage 1, the subject in stage 2: one of the two
    // composite keys fails whatever the stage column says.
    expect(fn () => insertMark($this, ['subject_id' => $second->id, 'stage' => 1]))->toThrow(QueryException::class, 'grade_marks_subject_id_stage_foreign')
        ->and(fn () => insertMark($this, ['subject_id' => $second->id, 'stage' => 2]))->toThrow(QueryException::class, 'grade_marks_student_id_stage_foreign');
});

it('refuses a second mark for the same student, subject and period', function () {
    insertMark($this);

    expect(fn () => insertMark($this, ['mark' => 50]))->toThrow(QueryException::class, 'unique');
});

it('refuses bad students, teachers and periods', function (string $table, array $row, string $constraint) {
    expect(fn () => inSavepoint(fn () => DB::table($table)->insert([...$row, 'created_at' => now(), 'updated_at' => now()])))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'student in section C' => ['grade_students', ['uuid' => (string) Str::uuid7(), 'full_name' => 'Some Student', 'name_key' => 'some student', 'stage' => 1, 'section' => 'C'], 'grade_students_section_check'],
    'student in stage 4' => ['grade_students', ['uuid' => (string) Str::uuid7(), 'full_name' => 'Some Student', 'name_key' => 'some student', 'stage' => 4, 'section' => 'A'], 'grade_students_stage_check'],
    'student with a blank name' => ['grade_students', ['uuid' => (string) Str::uuid7(), 'full_name' => '   ', 'name_key' => 'xyz', 'stage' => 1, 'section' => 'A'], 'grade_students_full_name_check'],
    'teacher with a raw code' => ['grade_teachers', ['uuid' => (string) Str::uuid7(), 'name' => 'Teacher', 'code_digest' => str_pad('my-code', 64, 'x')], 'grade_teachers_code_digest_check'],
    'a second row for one period' => ['grade_periods', ['period' => 'month_1', 'status' => 'closed'], 'grade_periods_period_unique'],
]);

it('refuses an open period without its opening time, and a closed one without its closing time', function () {
    expect(fn () => inSavepoint(fn () => DB::table('grade_periods')->update(['opened_at' => null])))->toThrow(QueryException::class, 'grade_periods_open_check')
        ->and(fn () => inSavepoint(fn () => DB::table('grade_periods')->update(['status' => 'closed'])))->toThrow(QueryException::class, 'grade_periods_closed_check');
});

it('keeps a name unique within its stage when students have no number', function () {
    expect(fn () => inSavepoint(fn () => Student::factory()->create(['full_name' => 'Twin', 'name_key' => $this->student->name_key])))
        ->toThrow(QueryException::class, 'grade_students_stage_name_key_unique');

    // With a number, the same name is allowed (two real people).
    Student::factory()->create(['name_key' => $this->student->name_key, 'student_number' => '42']);
    expect(Student::query()->count())->toBe(2);
});

it('keeps the first stage in one group, codes in pairs, and publication to closed periods', function () {
    expect(fn () => inSavepoint(fn () => Student::factory()->in(Stage::First, Section::B)->create()))
        ->toThrow(QueryException::class, 'grade_students_stage_sections_check')
        ->and(fn () => inSavepoint(fn () => DB::table('grade_students')->where('id', $this->student->id)->update(['code_digest' => str_repeat('a', 64)])))
        ->toThrow(QueryException::class, 'grade_students_code_pair_check')
        // The period from beforeEach is open.
        ->and(fn () => inSavepoint(fn () => DB::table('grade_periods')->update(['results_published_at' => now()])))
        ->toThrow(QueryException::class, 'grade_periods_published_closed_check');
});

it('will not delete a teacher who has subjects', function () {
    expect(fn () => inSavepoint(fn () => Teacher::query()->whereKey($this->subject->teacher_id)->delete()))->toThrow(QueryException::class, 'grade_subjects_teacher_id_foreign');
});
