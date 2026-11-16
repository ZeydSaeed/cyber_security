<?php

use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Application\StudentCodes;
use App\Modules\Grades\Application\TeacherCodes;
use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
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
    it('shows each teacher\'s employee number and subjects, and audits the showing', function () {
        $subject = Subject::factory()->unassigned()->stage(Stage::Second)->create(['name' => 'Networks']);
        $this->actingAs($this->owner)->post('/admin/teachers', [
            'name' => 'Huda Karim', 'code' => 'EMP-20241', 'subjects' => ['', $subject->uuid],
        ])->assertRedirect('/admin/teachers');
        $teacher = Teacher::query()->sole();
        expect($teacher->code_encrypted)->not->toBeNull()->not->toContain('EMP-20241')
            ->and($subject->refresh()->teacher_id)->toBe($teacher->id);

        $this->get('/admin/teachers')->assertInertia(fn (Assert $page) => $page
            ->where('teachers.0.code', 'EMP-20241')
            ->missing('teachers.0.code_encrypted')
            ->where('subjects.0.name', 'Networks')
            ->where('subjects.0.stage', 2)
            ->where('subjects.0.sections', ['A', 'B'])
            ->where('subjects.0.teacher', $teacher->uuid));
        expect(AuditEvent::query()->where('action', 'grades.teacher_codes_viewed')->count())->toBe(1);

        $this->put("/admin/teachers/{$teacher->uuid}", ['name' => 'Huda K.', 'code' => 'EMP-30000', 'subjects' => ['']]);
        expect(app(TeacherCodes::class)->decrypt((string) $teacher->refresh()->code_encrypted))->toBe('EMP-30000')
            ->and($teacher->name)->toBe('Huda K.')
            ->and($subject->refresh()->teacher_id)->toBeNull();
    });

    it('moves a subject chosen in a teacher\'s form from the teacher who had it', function () {
        $subject = Subject::factory()->create();
        $other = Teacher::factory()->withCode('other-code-1')->create();

        $this->actingAs($this->owner)->put("/admin/teachers/{$other->uuid}", ['subjects' => ['', $subject->uuid]]);
        expect($subject->refresh()->teacher_id)->toBe($other->id);

        // A form without the subjects field leaves them as they are.
        $this->put("/admin/teachers/{$other->uuid}", ['name' => 'Renamed Teacher']);
        expect($subject->refresh()->teacher_id)->toBe($other->id);
    });

    it('keeps a copy when an old teacher\'s code is set to the same again', function () {
        $teacher = Teacher::factory()->withCode('old-code-123')->create();
        expect($teacher->code_encrypted)->toBeNull();

        $this->actingAs($this->owner)->put("/admin/teachers/{$teacher->uuid}", ['code' => 'old-code-123']);
        expect($teacher->refresh()->code_encrypted)->not->toBeNull();
    });

    it('deletes a teacher without marks and leaves their subjects without a teacher', function () {
        $subject = Subject::factory()->create();
        $teacher = $subject->teacher;

        $this->actingAs($this->owner)->delete("/admin/teachers/{$teacher->uuid}")->assertRedirect('/admin/teachers');

        expect(Teacher::query()->count())->toBe(0)
            ->and($subject->refresh()->teacher_id)->toBeNull()
            ->and(AuditEvent::query()->where('action', 'grades.teacher_deleted')->exists())->toBeTrue();
    });

    it('refuses to delete a teacher who has entered marks', function () {
        GradesFixtures::openWindow();
        $subject = Subject::factory()->create();
        $student = Student::factory()->create();
        app(SaveMarks::class)->handle($subject->teacher, $subject, Section::A, Period::Month1, [$student->uuid => Mark::of(80)]);

        $this->actingAs($this->owner)->from('/admin/teachers')
            ->delete("/admin/teachers/{$subject->teacher->uuid}")
            ->assertSessionHasErrors('teacher');
        expect(Teacher::query()->count())->toBe(1);
    });
});

describe('subjects', function () {
    it('changes a subject\'s stage while it has no marks, and deletes it', function () {
        $subject = Subject::factory()->create(['name' => 'Networks']);

        $this->actingAs($this->owner)->put("/admin/subjects/{$subject->uuid}", ['name' => 'Networks', 'stage' => 2, 'teacher' => ''])
            ->assertRedirect('/admin/subjects');
        expect($subject->refresh()->stage)->toBe(Stage::Second)->and($subject->teacher_id)->toBeNull();

        $this->delete("/admin/subjects/{$subject->uuid}")->assertRedirect('/admin/subjects');
        expect(Subject::query()->count())->toBe(0);
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
            ->where('columns.order', ['full_name', 'code', 'stage', 'section']));
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
