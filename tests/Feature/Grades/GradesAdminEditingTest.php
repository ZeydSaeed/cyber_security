<?php

use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Application\StudentCodes;
use App\Modules\Grades\Application\TeacherCodes;
use App\Modules\Grades\Database\Factories\SubjectFactory;
use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\StudentStatus;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Support\Grades\GradesFixtures;

/*
| The owner's changes by hand (owner's request, 2026-11-16): teachers'
| employee numbers shown again and teachers deleted; a subject's stage
| changed and subjects deleted; students added, changed and deleted; the
| import's file to fill. Records with marks are protected.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = GradesFixtures::owner();
});

describe('teachers', function () {
    it('makes the teacher a code, shows it with the specialty and subjects, and audits the showing', function () {
        $subject = Subject::factory()->unassigned()->stage(Stage::Second)->create(['name' => 'Networks']);
        $this->actingAs($this->owner)->post('/admin/teachers', [
            'name' => 'Huda Karim', 'specialty' => ' Electricity ', 'subjects' => ['', $subject->uuid],
        ])->assertRedirect('/admin/teachers');
        $teacher = Teacher::query()->sole();
        $code = app(TeacherCodes::class)->decrypt((string) $teacher->code_encrypted);
        expect($teacher->specialty)->toBe('Electricity')
            ->and(SubjectFactory::teacherOf($subject)?->id)->toBe($teacher->id)
            ->and($teacher->code_digest)->toBe(app(TeacherCodes::class)->digest($code));

        $this->get('/admin/teachers')->assertInertia(fn (Assert $page) => $page
            ->where('teachers.0.code', $code)
            ->where('teachers.0.specialty', 'Electricity')
            ->missing('teachers.0.code_encrypted')
            ->where('subjects.0.name', 'Networks')
            ->where('subjects.0.stage', 2)
            ->where('subjects.0.sections', ['A', 'B'])
            // A subject chosen without a section: every section of its stage.
            ->where('subjects.0.teachers', ['A' => $teacher->uuid, 'B' => $teacher->uuid]));
        expect(AuditEvent::query()->where('action', 'grades.teacher_codes_viewed')->count())->toBe(1);

        $this->put("/admin/teachers/{$teacher->uuid}", ['name' => 'Huda K.', 'specialty' => '', 'subjects' => ['']]);
        $teacher->refresh();
        expect($teacher->name)->toBe('Huda K.')
            ->and($teacher->specialty)->toBeNull()
            ->and(SubjectFactory::teacherOf($subject)?->id)->toBeNull();
    });

    it('makes teacher codes in the student shape, always starting with T, and student codes never with T', function () {
        $teacher = collect(range(1, 300))->map(fn () => app(TeacherCodes::class)->generate());
        $student = collect(range(1, 300))->map(fn () => app(StudentCodes::class)->generate());

        foreach ($teacher as $code) {
            expect($code)->toMatch('/^T[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{3}-[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{4}$/');
        }
        foreach ($student as $code) {
            expect($code)->toMatch('/^[ABCDEFGHJKMNPQRSUVWXYZ2-9][ABCDEFGHJKMNPQRSTUVWXYZ2-9]{3}-[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{4}$/');
        }
        // Random: 300 codes from 31^7 do not repeat in practice.
        expect($teacher->unique()->count())->toBe(300);
    });

    it('refuses a typed student code that starts with T', function () {
        $student = Student::factory()->named('Sara Ali')->create();

        $this->actingAs($this->owner)->from('/admin/students')
            ->put("/admin/students/{$student->uuid}", ['name' => 'Sara Ali', 'stage' => 1, 'code' => 'TABC-2345'])
            ->assertSessionHasErrors(['code' => 'A code for a student may not start with T: that letter marks the code of a teacher.']);
    });

    it('prints every teacher on a slip with specialty, subjects and code, audited', function () {
        $subject = Subject::factory()->unassigned()->stage(Stage::Second)->create(['name' => 'Networks']);
        $this->actingAs($this->owner)->post('/admin/teachers', [
            'name' => 'Huda Karim', 'specialty' => 'Electricity', 'subjects' => ['', $subject->uuid],
        ]);
        $code = app(TeacherCodes::class)->decrypt((string) Teacher::query()->sole()->code_encrypted);

        $this->get('/admin/teachers/print')->assertInertia(fn (Assert $page) => $page
            ->component('modules/grades/pages/print/teachers')
            ->where('teachers.0.name', 'Huda Karim')
            ->where('teachers.0.specialty', 'Electricity')
            ->where('teachers.0.code', $code)
            ->where('teachers.0.subjects.0.name', 'Networks')
            ->where('teachers.0.subjects.0.stage', 2)
            ->where('teachers.0.subjects.0.sections', ['A', 'B'])
            ->where('teacherLink', route('grades.teacher.login')));
        expect(AuditEvent::query()->where('action', 'grades.teacher_codes_viewed')->where('metadata->printed', true)->exists())->toBeTrue();
    });

    it('replaces a teacher\'s code after the owner confirms; the old one stops working', function () {
        $this->actingAs($this->owner)->post('/admin/teachers', ['name' => 'Huda Karim']);
        $teacher = Teacher::query()->sole();
        $old = app(TeacherCodes::class)->decrypt((string) $teacher->code_encrypted);
        $version = $teacher->lock_version;

        $this->post("/admin/teachers/{$teacher->uuid}/code")->assertRedirect('/admin/teachers');
        $teacher->refresh();
        $new = app(TeacherCodes::class)->decrypt((string) $teacher->code_encrypted);

        expect($new)->not->toBe($old)
            ->and($teacher->lock_version)->toBe($version + 1)
            ->and(AuditEvent::query()->where('action', 'grades.teacher_code_changed')->exists())->toBeTrue();

        auth()->logout();
        $this->post('/teacher', ['code' => $old])->assertSessionHasErrors();
        // Case, spaces and the dash do not matter, as for students.
        $this->post('/teacher', ['code' => strtolower(str_replace('-', ' ', $new))])->assertRedirect();
        expect(auth('teacher')->id())->toBe($teacher->id);
    });

    it('moves a subject chosen in a teacher\'s form from the teacher who had it', function () {
        $subject = Subject::factory()->create();
        $other = Teacher::factory()->withCode('other-code-1')->create();

        $this->actingAs($this->owner)->put("/admin/teachers/{$other->uuid}", ['subjects' => ['', $subject->uuid]]);
        expect(SubjectFactory::teacherOf($subject)?->id)->toBe($other->id);

        // A form without the subjects field leaves them as they are.
        $this->put("/admin/teachers/{$other->uuid}", ['name' => 'Renamed Teacher']);
        expect(SubjectFactory::teacherOf($subject)?->id)->toBe($other->id);
    });

    it('keeps a copy when an old teacher\'s code is set to the same again', function () {
        $teacher = Teacher::factory()->withCode('old-code-123')->create();
        expect($teacher->code_encrypted)->toBeNull();

        $this->actingAs($this->owner)->put("/admin/teachers/{$teacher->uuid}", ['code' => 'old-code-123']);
        expect($teacher->refresh()->code_encrypted)->not->toBeNull();
    });

    it('deletes a teacher without marks and leaves their subjects without a teacher', function () {
        $subject = Subject::factory()->create();
        $teacher = SubjectFactory::teacherOf($subject);

        $this->actingAs($this->owner)->delete("/admin/teachers/{$teacher->uuid}")->assertRedirect('/admin/teachers');

        expect(Teacher::query()->count())->toBe(0)
            ->and(SubjectFactory::teacherOf($subject)?->id)->toBeNull()
            ->and(AuditEvent::query()->where('action', 'grades.teacher_deleted')->exists())->toBeTrue();
    });

    it('refuses to delete a teacher who has entered marks', function () {
        GradesFixtures::openWindow();
        $subject = Subject::factory()->create();
        $student = Student::factory()->create();
        app(SaveMarks::class)->handle(SubjectFactory::teacherOf($subject), $subject, Section::A, Period::Month1, [$student->uuid => Mark::of(80)]);

        $this->actingAs($this->owner)->from('/admin/teachers')
            ->delete('/admin/teachers/'.SubjectFactory::teacherOf($subject)?->uuid)
            ->assertSessionHasErrors('teacher');
        expect(Teacher::query()->count())->toBe(1);
    });
});

describe('subjects', function () {
    it('changes a subject\'s stage while it has no marks, and deletes it', function () {
        $subject = Subject::factory()->create(['name' => 'Networks']);

        $this->actingAs($this->owner)->put("/admin/subjects/{$subject->uuid}", ['name' => 'Networks', 'stage' => 2, 'teacher' => ''])
            ->assertRedirect('/admin/subjects');
        expect($subject->refresh()->stage)->toBe(Stage::Second)->and(SubjectFactory::teacherOf($subject)?->id)->toBeNull();

        $this->delete("/admin/subjects/{$subject->uuid}")->assertRedirect('/admin/subjects');
        expect(Subject::query()->count())->toBe(0);
    });

    it('prints each stage with its subjects, the third stage included', function () {
        Subject::factory()->unassigned()->create(['name' => 'Networks']);
        Subject::factory()->unassigned()->stage(Stage::Third)->create(['name' => 'Forensics']);

        $this->actingAs($this->owner)->get('/admin/subjects/print')->assertInertia(fn (Assert $page) => $page
            ->component('modules/grades/pages/print/subjects')
            ->where('stages.0', ['stage' => 1, 'subjects' => ['Networks']])
            ->where('stages.1', ['stage' => 2, 'subjects' => []])
            ->where('stages.2', ['stage' => 3, 'subjects' => ['Forensics']]));
    });

    it('keeps the stage and the subject once it has marks', function () {
        $subject = Subject::factory()->create();
        $student = Student::factory()->create();
        app(SaveMarks::class)->handle($this->owner, $subject, Section::A, Period::Month1, [$student->uuid => Mark::of(60)]);

        $this->actingAs($this->owner)->from('/admin/subjects')
            ->put("/admin/subjects/{$subject->uuid}", ['stage' => 2])
            ->assertSessionHasErrors('stage');
        $this->delete("/admin/subjects/{$subject->uuid}")->assertSessionHasErrors('subject');

        expect($subject->refresh()->stage)->toBe(Stage::First);
    });
});

describe('students', function () {
    it('adds a student with a code, the first stage always in section A', function () {
        $this->actingAs($this->owner)->from('/admin/students')
            ->post('/admin/students', ['name' => 'Noor Adel', 'stage' => 1, 'section' => 'B'])
            ->assertRedirect('/admin/students');

        $student = Student::query()->sole();
        expect($student->section)->toBe(Section::A)
            ->and($student->student_number)->toBeNull()
            ->and($student->code_digest)->not->toBeNull();

        $this->post('/admin/students', ['name' => 'Noor  Adel', 'stage' => 1])->assertSessionHasErrors('name');
        $this->post('/admin/students', ['name' => 'X', 'stage' => 2, 'section' => 'A'])->assertSessionHasErrors('name');
    });

    it('keeps the status of a student: external, or empty for a regular one', function () {
        $this->actingAs($this->owner)->from('/admin/students')
            ->post('/admin/students', ['name' => 'Ali Karim', 'stage' => 1, 'status' => 'external'])
            ->assertRedirect('/admin/students');
        $student = Student::query()->sole();
        expect($student->status)->toBe(StudentStatus::External);

        $this->get('/admin/students')->assertInertia(fn (Assert $page) => $page
            ->where('students.rows.0.status', 'external')
            ->where('columns.order', ['full_name', 'code', 'stage', 'section', 'status', 'login_count']));

        $this->put("/admin/students/{$student->uuid}", ['name' => 'Ali Karim', 'stage' => 1, 'status' => '']);
        expect($student->refresh()->status)->toBeNull();

        $this->put("/admin/students/{$student->uuid}", ['name' => 'Ali Karim', 'stage' => 1, 'status' => 'absent'])
            ->assertSessionHasErrors('status');
    });

    it('changes a student and their code, and refuses a weak or taken code', function () {
        $student = Student::factory()->named('Sara Ali')->create();
        $other = Student::factory()->named('Other One')->create();
        $this->actingAs($this->owner)->post('/admin/students/codes');
        $otherCode = app(StudentCodes::class)->decrypt((string) $other->refresh()->code_encrypted);

        $this->from('/admin/students')
            ->put("/admin/students/{$student->uuid}", ['name' => 'Sara M. Ali', 'stage' => 2, 'section' => 'B', 'code' => 'sara-2026-ab'])
            ->assertRedirect('/admin/students');
        $student->refresh();
        expect($student->full_name)->toBe('Sara M. Ali')
            ->and($student->stage)->toBe(Stage::Second)
            ->and($student->section)->toBe(Section::B)
            ->and($student->code_digest)->toBe(app(StudentCodes::class)->digest('SARA2026AB'))
            ->and(AuditEvent::query()->where('action', 'grades.student_code_replaced')->exists())->toBeTrue();

        // The student signs in with the typed code.
        $this->post('/student', ['code' => 'SARA 2026 AB'])->assertRedirect();
        expect(auth('student')->id())->toBe($student->id);
        auth('student')->logout();

        $this->put("/admin/students/{$student->uuid}", ['name' => 'Sara M. Ali', 'stage' => 2, 'section' => 'B', 'code' => 'short'])
            ->assertSessionHasErrors('code');
        $this->put("/admin/students/{$student->uuid}", ['name' => 'Sara M. Ali', 'stage' => 2, 'section' => 'B', 'code' => $otherCode])
            ->assertSessionHasErrors('code');
    });

    it('lists each student\'s code for those who manage, and audits it', function () {
        Student::factory()->named('Sara Ali')->create();
        $this->actingAs($this->owner)->post('/admin/students/codes');
        $code = app(StudentCodes::class)->decrypt((string) Student::query()->sole()->code_encrypted);

        $this->get('/admin/students')->assertInertia(fn (Assert $page) => $page
            ->where('students.rows.0.code', $code)
            ->where('columns.order', ['full_name', 'code', 'stage', 'section', 'status', 'login_count']));
        expect(AuditEvent::query()->where('action', 'grades.student_codes_listed')->count())->toBe(1);

        // Found by its code.
        $this->get('/admin/students?search='.strtolower($code))->assertInertia(fn (Assert $page) => $page
            ->where('students.total', 1));
    });

    it('keeps the stage of a student with marks and refuses to delete them', function () {
        $subject = Subject::factory()->create();
        $student = Student::factory()->create();
        app(SaveMarks::class)->handle($this->owner, $subject, Section::A, Period::Month1, [$student->uuid => Mark::of(60)]);

        $this->actingAs($this->owner)->from('/admin/students')
            ->put("/admin/students/{$student->uuid}", ['name' => $student->full_name, 'stage' => 2, 'section' => 'A'])
            ->assertSessionHasErrors('stage');
        $this->delete("/admin/students/{$student->uuid}")->assertSessionHasErrors('student');

        $fresh = Student::factory()->create();
        $this->delete("/admin/students/{$fresh->uuid}")->assertRedirect('/admin/students');
        expect(Student::query()->count())->toBe(1);
    });

    it('gives a file to fill whose first sheet holds only headers the import reads', function () {
        $response = $this->actingAs($this->owner)->get('/admin/students/import/template')->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();

        $reader = new Reader;
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            $sheets[] = $rows;
        }
        $reader->close();

        expect($sheets)->toHaveCount(2)
            ->and($sheets[0])->toBe([['Name', 'Stage', 'Section', 'Student number']]);
    });

    it('is for managers only', function () {
        $this->get('/admin/students/import/template')->assertRedirect('/login');
    });
});
