<?php

use App\Access\Role;
use App\Models\User;
use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Application\TeacherCodes;
use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\GradeMark;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Access\RoleAssignment;
use App\Platform\Audit\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Support\Grades\GradesFixtures;

/*
| The owner's pages (ADR-0034): who may open them, the set-up of teachers
| and subjects, the student import, the results (checked against figures
| worked out by hand), printing, the Excel file, and corrections.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = GradesFixtures::owner();
});

describe('access', function () {
    it('is for those with the grades permissions only', function (string $method, string $url) {
        $auditor = User::factory()->withTwoFactor()->create();
        RoleAssignment::query()->forceCreate(['user_id' => $auditor->id, 'role' => Role::Auditor->value]);

        $this->actingAs(User::factory()->withTwoFactor()->create())->call($method, $url)->assertForbidden();
        $this->actingAs($auditor)->call($method, $url)->assertForbidden();
        auth()->logout();
        $this->call($method, $url)->assertRedirect('/login');
    })->with([
        ['GET', '/admin'],
        ['GET', '/admin/results'],
        ['GET', '/admin/students'],
        ['GET', '/admin/teachers'],
        ['POST', '/admin/teachers'],
        ['PUT', '/admin/window'],
        ['POST', '/admin/students/import'],
    ]);

    it('keeps teachers out of the owner\'s pages', function () {
        Teacher::factory()->withCode('teacher-code-1')->create();
        $this->post('/teacher', ['code' => 'teacher-code-1']);

        $this->get('/admin')->assertRedirect('/login');
    });
});

describe('set-up', function () {
    it('shows each stage\'s subjects with their progress, and opens and closes entry', function () {
        $subject = Subject::factory()->create(['name' => 'Cryptography']);
        Student::factory()->count(2)->create();
        Subject::factory()->unassigned()->stage(Stage::Second)->create();

        $this->actingAs($this->owner)->get('/admin')->assertInertia(fn (Assert $page) => $page
            ->component('modules/grades/pages/admin/overview')
            ->where('window.open', false)
            ->where('expected_subjects', 8)
            // The first stage is one group; the second has two sections.
            ->where('stages.0.divided', false)
            ->where('stages.0.students', ['A' => 2])
            ->where('stages.1.divided', true)
            ->where('stages.1.students', ['A' => 0, 'B' => 0])
            ->where('stages.0.subjects.0.name', 'Cryptography')
            ->where('stages.0.subjects.0.teacher', $subject->teacher?->name)
            ->where('stages.0.subjects.0.sections', ['A' => 0])
            ->where('stages.1.subjects.0.teacher', null)
            ->where('teacherLink', route('grades.teacher.login'))
            ->where('studentLink', route('grades.student.login'))
            ->where('window.published', false)
            ->where('canManage', true));

        $this->put('/admin/window', ['status' => 'open'])->assertRedirect();
        expect(GradePeriod::for(Period::Month1)->status)->toBe(WindowStatus::Open);

        $this->put('/admin/window', ['status' => 'closed']);
        expect(GradePeriod::for(Period::Month1)->status)->toBe(WindowStatus::Closed);
        $this->put('/admin/window', ['status' => 'ajar'])->assertSessionHasErrors('status');
    });

    it('adds a teacher, refuses a weak code beside its field, and changes name, code and access', function () {
        $this->actingAs($this->owner)->from('/admin/teachers')
            ->post('/admin/teachers', ['name' => 'Huda Karim', 'code' => 'short'])
            ->assertSessionHasErrors(['code' => 'The code must be between 8 and 64 characters.']);

        $this->post('/admin/teachers', ['name' => 'Huda Karim', 'code' => 'quiet-lake-77'])->assertRedirect('/admin/teachers');
        $teacher = Teacher::query()->sole();

        $this->get('/admin/teachers')->assertInertia(fn (Assert $page) => $page
            ->where('teachers.0.name', 'Huda Karim')
            ->where('teachers.0.active', true)
            ->missing('teachers.0.code_digest')
            ->missing('teachers.0.id'));

        $this->put("/admin/teachers/{$teacher->uuid}", ['name' => 'Huda K. Karim']);
        $this->put("/admin/teachers/{$teacher->uuid}", ['code' => 'bright-sky-31']);
        $this->put("/admin/teachers/{$teacher->uuid}", ['active' => false]);

        $teacher->refresh();
        expect($teacher->name)->toBe('Huda K. Karim')
            ->and($teacher->code_digest)->toBe(app(TeacherCodes::class)->digest('bright-sky-31'))
            ->and($teacher->is_active)->toBeFalse();
    });

    it('adds subjects to a stage and keeps a stopped teacher assigned when a subject is saved', function () {
        $teacher = Teacher::factory()->create(['name' => 'Omar']);

        $this->actingAs($this->owner)
            ->post('/admin/subjects', ['stage' => 2, 'name' => 'Forensics', 'teacher' => $teacher->uuid])
            ->assertRedirect('/admin/subjects');
        $subject = Subject::query()->sole();
        expect($subject->stage)->toBe(Stage::Second)->and($subject->teacher_id)->toBe($teacher->id);

        $teacher->forceFill(['is_active' => false])->save();
        $this->get('/admin/subjects')->assertInertia(fn (Assert $page) => $page
            ->where('stages.1.subjects.0.teacher', $teacher->uuid)
            // Teachers are chosen on the teachers' page now (2026-11-17).
            ->missing('teachers'));

        // The page sends both fields back; the stopped teacher stays.
        $this->put("/admin/subjects/{$subject->uuid}", ['name' => 'Digital Forensics', 'teacher' => $teacher->uuid]);
        expect($subject->fresh()?->name)->toBe('Digital Forensics')
            ->and($subject->fresh()?->teacher_id)->toBe($teacher->id);

        $this->put("/admin/subjects/{$subject->uuid}", ['name' => 'Digital Forensics', 'teacher' => '']);
        expect($subject->fresh()?->teacher_id)->toBeNull();
    });
});

describe('students', function () {
    it('finds students by a name typed differently, by number, and by stage and section', function () {
        Student::factory()->named("\u{0623}\u{062D}\u{0645}\u{062F} \u{0639}\u{0644}\u{064A} \u{062D}\u{0633}\u{0646}")->create(['student_number' => '501']);
        Student::factory()->named('Sara Kamal Yousif')->in(Stage::Second, Section::B)->create();

        $this->actingAs($this->owner);
        $this->get('/admin/students?search='.urlencode("\u{0627}\u{062D}\u{0645}\u{062F}"))
            ->assertInertia(fn (Assert $page) => $page->where('students.total', 1)->where('students.rows.0.number', '501'));
        $this->get('/admin/students?search=501')->assertInertia(fn (Assert $page) => $page->where('students.total', 1));
        $this->get('/admin/students?filter[stage]=2&filter[section]=B')
            ->assertInertia(fn (Assert $page) => $page->where('students.total', 1)->where('students.rows.0.name', 'Sara Kamal Yousif')->where('canImport', true));
    });

    it('imports in two steps: the upload is checked and previewed, and only apply saves', function () {
        Storage::fake((string) config('platform.files.disk'));
        $csv = "name,stage,section\nAli Hassan Jaber,1,A\nMona Salim Faraj,2,B\nX,9,Z\n";

        $this->actingAs($this->owner)
            ->post('/admin/students/import', ['file' => UploadedFile::fake()->createWithContent('students.csv', $csv)])
            ->assertRedirect('/admin/students/import');

        $this->get('/admin/students/import')->assertInertia(fn (Assert $page) => $page
            ->component('modules/grades/pages/admin/import-preview')
            ->where('file', 'students.csv')
            ->where('counts.created', 2)
            ->where('counts.rejected', 1)
            ->where('rejected.0.row', 4));
        expect(Student::query()->count())->toBe(0);

        $this->post('/admin/students/import/apply')->assertRedirect('/admin/students');
        expect(Student::query()->count())->toBe(2)
            ->and(AuditEvent::query()->where('action', 'file.uploaded')->count())->toBe(1)
            ->and(AuditEvent::query()->where('action', 'grades.students_imported')->count())->toBe(1);

        // Done: the preview is gone.
        $this->get('/admin/students/import')->assertRedirect('/admin/students');
    });

    it('refuses other file types before reading them', function () {
        Storage::fake((string) config('platform.files.disk'));

        $this->actingAs($this->owner)->from('/admin/students')
            ->post('/admin/students/import', ['file' => UploadedFile::fake()->createWithContent('students.pdf', "%PDF-1.4\n%%EOF\n")])
            ->assertSessionHasErrors(['file' => 'This type of file is not accepted.']);
    });
});

describe('results', function () {
    beforeEach(function () {
        // Stage 1 has three subjects; worked out by hand below.
        $this->subjects = collect(['Networks', 'Cryptography', 'Programming'])
            ->map(fn (string $name, int $i) => Subject::factory()->create(['name' => $name, 'sort_order' => $i + 1]));
        $this->ali = Student::factory()->named('Ali Hassan')->create(['student_number' => '11']);
        $this->mona = Student::factory()->named('Mona Salim')->create();
        $this->zaid = Student::factory()->named('Zaid Kareem')->create();
        Student::factory()->named('Other Stage')->in(Stage::Second, Section::A)->create();

        $save = function (Subject $subject, array $marks): void {
            app(SaveMarks::class)->handle($this->owner, $subject, Section::A, Period::Month1, $marks);
        };
        [$networks, $crypto, $programming] = $this->subjects->all();
        // Ali: 90 + 45 + absent -> 135 / 300 = 45.00 %, 2 below 50, 1 absence.
        // Mona: 50 + 100 + 75   -> 225 / 300 = 75.00 %, none below: passed.
        // Zaid: 60 + 70 + (none) -> incomplete, 1 missing.
        $save($networks, [$this->ali->uuid => Mark::of(90), $this->mona->uuid => Mark::of(50), $this->zaid->uuid => Mark::of(60)]);
        $save($crypto, [$this->ali->uuid => Mark::of(45), $this->mona->uuid => Mark::of(100), $this->zaid->uuid => Mark::of(70)]);
        $save($programming, [$this->ali->uuid => Mark::absent(), $this->mona->uuid => Mark::of(75)]);
    });

    it('matches the results worked out by hand', function () {
        $this->actingAs($this->owner)->get('/admin/results?stage=1&section=A')->assertInertia(fn (Assert $page) => $page
            ->component('modules/grades/pages/admin/results')
            ->where('pass_mark', 50)
            ->where('subjects.0.name', 'Networks')
            ->has('students', 3)
            ->where('students.0.name', 'Ali Hassan')
            ->where('students.0.marks', [90, 45, 'absent'])
            ->where('students.0.total', 135)
            ->where('students.0.maximum', 300)
            ->where('students.0.percentage', 45)
            ->where('students.0.below', 2)
            ->where('students.0.absences', 1)
            ->where('students.0.complete', true)
            ->where('students.0.passed', false)
            ->where('students.1.name', 'Mona Salim')
            ->where('students.1.total', 225)
            ->where('students.1.percentage', 75)
            ->where('students.1.passed', true)
            ->where('students.2.name', 'Zaid Kareem')
            ->where('students.2.marks', [60, 70, null])
            ->where('students.2.missing', 1)
            ->where('students.2.complete', false)
            ->where('summary', ['students' => 3, 'complete' => 2, 'passed_all' => 1]));
    });

    it('prints a section and a student\'s card', function () {
        $this->actingAs($this->owner);
        $this->get('/admin/results/print?stage=1&section=A')
            ->assertInertia(fn (Assert $page) => $page->component('modules/grades/pages/print/section')->has('students', 3));
        $this->get("/admin/results/students/{$this->mona->uuid}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('modules/grades/pages/print/student')
                ->where('section', 'A')
                ->where('student.name', 'Mona Salim')
                ->where('student.marks', [50, 100, 75])
                ->where('student.passed', true));
    });

    it('exports the section as an Excel file with the same figures, and records it', function () {
        $response = $this->actingAs($this->owner)->get('/admin/results/export?stage=1&section=A');
        $response->assertOk()->assertDownload('results-stage1-A.xlsx');

        $file = $response->baseResponse->getFile()->getPathname();
        $rows = [];
        $reader = new Reader;
        $reader->open($file);
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();

        expect($rows[0])->toBe(['Number', 'Name', 'Networks', 'Cryptography', 'Programming', 'Total out of 300', 'Percentage', 'Below 50', 'Absences'])
            ->and($rows[1])->toBe(['11', 'Ali Hassan', 90, 45, 'Absent', 135, 45, 2, 1])
            ->and($rows[3][5])->toBe('Incomplete')
            ->and(AuditEvent::query()->where('action', 'grades.results_exported')->sole()->metadata)->toBe(['stage' => 1, 'section' => 'A']);
    });

    it('lets the owner correct a mark while entry is closed, under the owner\'s name', function () {
        [$networks] = $this->subjects->all();

        $this->actingAs($this->owner)
            ->get("/admin/subjects/{$networks->uuid}/A")
            ->assertInertia(fn (Assert $page) => $page->component('modules/grades/pages/admin/sheet')->where('open', false));

        $this->put("/admin/subjects/{$networks->uuid}/A", ['marks' => [$this->ali->uuid => '95']])
            ->assertRedirect("/admin/subjects/{$networks->uuid}/A");

        $mark = GradeMark::query()->where('student_id', $this->ali->id)->where('subject_id', $networks->id)->sole();
        expect($mark->mark)->toBe(95)
            ->and($mark->entered_by_user_id)->toBe($this->owner->id)
            ->and(AuditEvent::query()->where('action', 'grades.marks_saved')->latest('id')->first()?->actor_id)->toBe($this->owner->id);
    });

    it('reads a section\'s results in the same few queries however many students it has', function () {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->owner)->get('/admin/results?stage=1&section=A')->assertOk();

            return count(DB::getQueryLog());
        };

        // The first request of a session reads a few things once
        // (QueryBudgetTest); compare two later ones.
        $count();
        $few = $count();
        Student::factory()->count(60)->create();

        expect($count())->toBe($few)->toBeLessThanOrEqual(8);
    });
});
