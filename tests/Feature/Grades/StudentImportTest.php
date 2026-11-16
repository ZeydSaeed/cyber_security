<?php

use App\Models\User;
use App\Modules\Grades\Application\Actions\ImportStudents;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Models\GradeMark;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Platform\Audit\AuditEvent;
use App\Platform\Exceptions\DomainRuleViolation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Grades\GradesFixtures;

/*
| Importing students from Excel or CSV (ADR-0034): a preview first, then
| the same plan applied; rows are matched, never deleted; every refusal has
| a reason and the spreadsheet's row number.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = GradesFixtures::owner();
    $this->files = [];
});

afterEach(function () {
    foreach ($this->files as $file) {
        @unlink($file);
    }
});

/**
 * @param  list<list<string|int>>  $rows
 */
function importFile(object $test, array $rows, string $extension = 'csv', string $delimiter = ','): string
{
    return $test->files[] = GradesFixtures::spreadsheet($rows, $extension, $delimiter);
}

it('reads an Excel file with Arabic headers and creates the students', function () {
    $name = "\u{0627}\u{0644}\u{0627}\u{0633}\u{0645}"; // name
    $stage = "\u{0627}\u{0644}\u{0635}\u{0641}"; // stage
    $section = "\u{0627}\u{0644}\u{0634}\u{0639}\u{0628}\u{0629}"; // section
    $file = importFile($this, [
        [$name, $stage, $section],
        ["\u{0623}\u{062D}\u{0645}\u{062F} \u{0639}\u{0644}\u{064A} \u{062D}\u{0633}\u{0646}", 1, 'A'],
        ['Sara Kamal Yousif', 2, "\u{0628}"],
    ], 'xlsx');

    $preview = app(ImportStudents::class)->preview($this->owner, $file, 'xlsx');
    expect($preview->counts())->toBe(['created' => 2, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0, 'not_in_file' => 0])
        ->and(Student::query()->count())->toBe(0);

    app(ImportStudents::class)->apply($this->owner, $file, 'xlsx', 'students.xlsx');

    $sara = Student::query()->where('full_name', 'Sara Kamal Yousif')->sole();
    expect(Student::query()->count())->toBe(2)
        ->and($sara->stage)->toBe(Stage::Second)
        ->and($sara->section)->toBe(Section::B);

    $audit = AuditEvent::query()->where('action', 'grades.students_imported')->sole();
    expect($audit->metadata)->toMatchArray(['created' => 2, 'file' => 'students.xlsx'])
        ->and($audit->actor_id)->toBe($this->owner->id);
});

it('reads columns in order when there is no header, and a semicolon CSV with a byte order mark', function () {
    $file = importFile($this, [["\u{FEFF}Ali Hassan Jaber", '1', 'a', '1001'], ['Mona Salim Faraj', '2', 'B', '1002']], 'csv', ';');

    app(ImportStudents::class)->apply($this->owner, $file, 'csv');

    expect(Student::query()->orderBy('student_number')->pluck('full_name', 'student_number')->all())
        ->toBe(['1001' => 'Ali Hassan Jaber', '1002' => 'Mona Salim Faraj']);
});

it('rejects bad rows with their reason and row number, and imports the rest', function () {
    $file = importFile($this, [
        ['name', 'stage', 'section', 'number'],
        ['Good Student One', '1', 'A', ''],
        ['Al', '1', 'A', ''],
        ['Fourth Year Student', '4', 'A', ''],
        ['Section C Student', '2', 'C', ''],
        ['First Stage Section B', '1', 'B', ''],
        ['Good Student One', '1', '', ''],
        ['Numbered Student', '2', 'A', 'N-7'],
        ['Other Name Same Number', '2', 'B', 'N-7'],
    ]);

    $plan = app(ImportStudents::class)->apply($this->owner, $file, 'csv');

    expect(Student::query()->count())->toBe(2)
        ->and($plan->rejected)->toBe([
            ['row' => 3, 'reason' => 'The name is missing or too short.'],
            ['row' => 4, 'reason' => 'The stage must be 1, 2 or 3.'],
            ['row' => 5, 'reason' => 'The section must be A or B.'],
            ['row' => 6, 'reason' => 'The first stage has no sections; leave the section empty.'],
            ['row' => 7, 'reason' => 'Repeats row 2 of the file.'],
            ['row' => 9, 'reason' => 'Repeats row 8 of the file.'],
        ]);
});

it('changes nothing when the same file is applied twice', function () {
    $file = importFile($this, [['name', 'stage', 'section'], ['Student Alpha One', '1', 'A'], ['Student Beta Two', '2', 'B']]);

    app(ImportStudents::class)->apply($this->owner, $file, 'csv');
    $second = app(ImportStudents::class)->apply($this->owner, $file, 'csv');

    expect($second->counts())->toBe(['created' => 0, 'updated' => 0, 'unchanged' => 2, 'rejected' => 0, 'not_in_file' => 0])
        ->and(Student::query()->count())->toBe(2)
        ->and(AuditEvent::query()->where('action', 'grades.students_imported')->count())->toBe(1);
});

it('matches a name typed differently, and updates the section, the spelling and the number', function () {
    Student::factory()->named("\u{0627}\u{062D}\u{0645}\u{062F} \u{0639}\u{0644}\u{064A} \u{062D}\u{0633}\u{0646}")->in(Stage::Second, Section::A)->create();
    $file = importFile($this, [
        ['name', 'stage', 'section', 'number'],
        ["\u{0623}\u{062D}\u{0645}\u{062F}  \u{0639}\u{0644}\u{064A} \u{062D}\u{0633}\u{0646}", '2', 'B', '501'],
    ]);

    $plan = app(ImportStudents::class)->apply($this->owner, $file, 'csv');
    $student = Student::query()->sole();

    expect($plan->counts()['updated'])->toBe(1)
        ->and($student->section)->toBe(Section::B)
        ->and($student->student_number)->toBe('501')
        ->and($student->full_name)->toBe("\u{0623}\u{062D}\u{0645}\u{062F} \u{0639}\u{0644}\u{064A} \u{062D}\u{0633}\u{0646}")
        ->and(array_keys($plan->updates[0]['changes']))->toBe(['full_name', 'section', 'student_number']);
});

it('matches by student number before name, and never removes a number or a student', function () {
    Student::factory()->named('Old Spelling Name')->create(['student_number' => '900']);
    Student::factory()->named('Not In The File')->create();
    $file = importFile($this, [['name', 'stage', 'section', 'number'], ['New Spelling Name', '1', 'A', '900']]);

    $plan = app(ImportStudents::class)->apply($this->owner, $file, 'csv');

    expect($plan->counts())->toMatchArray(['created' => 0, 'updated' => 1, 'not_in_file' => 1])
        ->and(Student::query()->where('student_number', '900')->value('full_name'))->toBe('New Spelling Name')
        ->and(Student::query()->count())->toBe(2);
});

it('will not move a student with marks to the other stage', function () {
    $student = Student::factory()->named('Has Marks Already')->create(['student_number' => '77']);
    $subject = Subject::factory()->create();
    GradePeriod::for(Period::Month1);
    (new GradeMark)->forceFill([
        'student_id' => $student->id, 'subject_id' => $subject->id, 'stage' => Stage::First,
        'period' => Period::Month1, 'mark' => 80, 'entered_by_teacher_id' => $subject->teacher_id,
    ])->save();
    $file = importFile($this, [['name', 'stage', 'section', 'number'], ['Has Marks Already', '2', 'A', '77']]);

    $plan = app(ImportStudents::class)->apply($this->owner, $file, 'csv');

    expect($plan->rejected)->toBe([['row' => 2, 'reason' => 'This student already has marks in the First stage and cannot change stage.']])
        ->and($student->fresh()?->stage)->toBe(Stage::First);
});

it('asks for the number when a name is not enough to tell two students apart', function () {
    Student::factory()->named('Same Name Twice')->in(Stage::Second, Section::A)->create(['student_number' => '1']);
    Student::factory()->named('Same Name Twice')->in(Stage::Second, Section::B)->create(['student_number' => '2']);
    $file = importFile($this, [['name', 'stage', 'section'], ['Same Name Twice', '2', 'A']]);

    expect(app(ImportStudents::class)->preview($this->owner, $file, 'csv')->rejected)
        ->toBe([['row' => 2, 'reason' => 'More than one student in this stage has this name; add the student number.']]);
});

it('refuses other file types, unreadable files and files over the row limit', function () {
    config(['grades.import_max_rows' => 2]);
    $tooLong = importFile($this, [['A Student One', '1', 'A'], ['A Student Two', '1', 'A'], ['A Student Three', '1', 'A']]);
    $broken = $this->files[] = sys_get_temp_dir().DIRECTORY_SEPARATOR.'broken-'.bin2hex(random_bytes(4)).'.xlsx';
    file_put_contents($broken, 'not a zip file');

    expect(fn () => app(ImportStudents::class)->preview($this->owner, $tooLong, 'csv'))
        ->toThrow(DomainRuleViolation::class, 'The file has more than 2 rows.')
        ->and(fn () => app(ImportStudents::class)->preview($this->owner, $tooLong, 'pdf'))
        ->toThrow(DomainRuleViolation::class, 'Upload an Excel (.xlsx) or CSV file.')
        ->and(fn () => app(ImportStudents::class)->preview($this->owner, $broken, 'xlsx'))
        ->toThrow(DomainRuleViolation::class, 'The file could not be read as a spreadsheet.');
});

it('is the business of those who manage grades only', function () {
    $file = importFile($this, [['A Student One', '1', 'A']]);

    app(ImportStudents::class)->preview(User::factory()->withTwoFactor()->create(), $file, 'csv');
})->throws(AuthorizationException::class);

it('takes first-stage students without a section column, and gives every new student a code', function () {
    $file = importFile($this, [['name', 'stage'], ['First Stage Only', '1'], ['Another First Stager', '1']]);

    app(ImportStudents::class)->apply($this->owner, $file, 'csv');

    expect(Student::query()->pluck('section')->map(fn ($s) => $s->value)->all())->toBe(['A', 'A'])
        ->and(Student::query()->whereNull('code_digest')->count())->toBe(0)
        ->and(Student::query()->whereNull('code_encrypted')->count())->toBe(0);
});
