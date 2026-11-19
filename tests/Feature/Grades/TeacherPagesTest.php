<?php

use App\Modules\Grades\Application\Actions\SaveMarks;
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
use App\Platform\Audit\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Grades\GradesFixtures;

/*
| A teacher's pages (ADR-0034): their subjects with each section's
| progress, and a section's sheet to fill in. Another teacher's subject is
| a 404; wrong values are named per student; a closed window refuses.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    RateLimiter::clear('grades-teacher:minute:127.0.0.1');
    RateLimiter::clear('grades-teacher:hour:127.0.0.1');
    $this->teacher = Teacher::factory()->withCode('teacher-code-1')->create();
    $this->subject = Subject::factory()->create(['teacher_id' => $this->teacher->id, 'name' => 'Cryptography']);
    $this->students = collect(['Zainab Ali Saleh', 'Ahmed Omar Taha', 'Mariam Hadi Nasser'])
        ->map(fn (string $name) => Student::factory()->named($name)->create());
    // Another stage's students never count for this first-stage subject.
    Student::factory()->in(Stage::Second, Section::B)->count(2)->create();
    GradesFixtures::openWindow();

    $this->post('/teacher', ['code' => 'teacher-code-1']);
});

function sectionUrl(Subject $subject, string $section = 'A'): string
{
    return "/teacher/subjects/{$subject->uuid}/{$section}";
}

it('lists only the teacher\'s subjects, with each section\'s progress', function () {
    Subject::factory()->create(['name' => 'Someone else\'s']);
    (new GradeMark)->forceFill([
        'student_id' => $this->students[0]->id, 'subject_id' => $this->subject->id, 'stage' => Stage::First,
        'period' => Period::Month1, 'mark' => 75, 'entered_by_teacher_id' => $this->teacher->id,
    ])->save();

    $this->get('/teacher/subjects')->assertInertia(fn (Assert $page) => $page
        ->component('modules/grades/pages/teacher/subjects')
        ->where('portal.name', $this->teacher->name)
        ->where('portal.logout', route('grades.teacher.logout'))
        ->where('open', true)
        ->has('subjects', 1)
        ->where('subjects.0.name', 'Cryptography')
        // The first stage is one group: no section B.
        ->where('subjects.0.divided', false)
        ->where('subjects.0.sections', [
            ['section' => 'A', 'students' => 3, 'entered' => 1, 'access' => ['state' => 'edit', 'can_write' => true, 'hours' => null, 'until' => null]],
        ])
        // Nothing of the users' application.
        ->where('auth.user', null)
        ->where('auth.permissions', []));
});

it('shows a section\'s students in name order with their marks', function () {
    (new GradeMark)->forceFill([
        'student_id' => $this->students[0]->id, 'subject_id' => $this->subject->id, 'stage' => Stage::First,
        'period' => Period::Month1, 'mark' => null, 'is_absent' => true, 'entered_by_teacher_id' => $this->teacher->id,
    ])->save();

    $this->get(sectionUrl($this->subject))->assertInertia(fn (Assert $page) => $page
        ->component('modules/grades/pages/teacher/section')
        ->where('section', 'A')
        ->where('subject.name', 'Cryptography')
        ->where('students.0.name', 'Ahmed Omar Taha')
        ->where('students.1.name', 'Mariam Hadi Nasser')
        ->where('students.2.name', 'Zainab Ali Saleh')
        ->where('students.2.mark', 'absent')
        ->where('students.0.mark', null)
        ->missing('students.0.id'));
});

it('saves marks, absences and removals, and says when the section is complete', function () {
    [$zainab, $ahmed, $mariam] = $this->students->all();

    $this->put(sectionUrl($this->subject), ['marks' => [$zainab->uuid => '90', $ahmed->uuid => 'absent', $mariam->uuid => ' 7 ']])
        ->assertRedirect(sectionUrl($this->subject))
        ->assertInertiaFlash('toast.message', 'Saved. Every student in this section has a mark.');

    expect(GradeMark::query()->orderBy('student_id')->get()->map(fn (GradeMark $m) => $m->value()->toScalar())->all())
        ->toBe([90, 'absent', 7]);

    $this->put(sectionUrl($this->subject), ['marks' => [$zainab->uuid => '90', $ahmed->uuid => 'absent', $mariam->uuid => '']])
        ->assertInertiaFlash('toast.message', 'Saved.');
    $this->put(sectionUrl($this->subject), ['marks' => [$zainab->uuid => '90', $ahmed->uuid => 'absent', $mariam->uuid => '']])
        ->assertInertiaFlash('toast.message', 'Nothing changed.');

    expect(GradeMark::query()->count())->toBe(2)
        ->and(AuditEvent::query()->where('action', 'grades.marks_saved')->count())->toBe(2);
});

it('names each wrong value beside its student, and saves nothing', function () {
    [$zainab, $ahmed, $mariam] = $this->students->all();

    $this->from(sectionUrl($this->subject))
        ->put(sectionUrl($this->subject), ['marks' => [$zainab->uuid => '101', $ahmed->uuid => '12.5', $mariam->uuid => '80']])
        ->assertRedirect(sectionUrl($this->subject))
        ->assertSessionHasErrors([
            "marks.{$zainab->uuid}" => 'Enter a whole number from 0 to 100, or mark the student absent.',
            "marks.{$ahmed->uuid}" => 'Enter a whole number from 0 to 100, or mark the student absent.',
        ])
        ->assertSessionDoesntHaveErrors("marks.{$mariam->uuid}");

    expect(GradeMark::query()->count())->toBe(0);
});

it('refuses students of another stage, and a section the stage does not have', function () {
    $other = Student::query()->where('stage', 2)->firstOrFail();

    $this->from(sectionUrl($this->subject))
        ->put(sectionUrl($this->subject), ['marks' => [$other->uuid => '50']])
        ->assertSessionHasErrors(['marks' => 'Some students are not in this section.']);

    // The first stage has no section B.
    $this->get(sectionUrl($this->subject, 'B'))->assertNotFound();
    $this->put(sectionUrl($this->subject, 'B'), ['marks' => [$other->uuid => '50']])->assertNotFound();
});

it('hides another teacher\'s subject and rejects malformed addresses', function () {
    $other = Subject::factory()->create();

    $this->get(sectionUrl($other))->assertNotFound();
    $this->put(sectionUrl($other), ['marks' => [$this->students[0]->uuid => '50']])->assertNotFound();
    $this->get('/teacher/subjects/not-a-uuid/A')->assertNotFound();
    $this->get(sectionUrl($this->subject, 'C'))->assertNotFound();

    expect(GradeMark::query()->count())->toBe(0);
});

it('shows the sheet read-only and refuses saving while entry is closed', function () {
    GradePeriod::for(Period::Month1)->forceFill(['status' => WindowStatus::Closed, 'closed_at' => now()])->save();

    $this->get(sectionUrl($this->subject))->assertInertia(fn (Assert $page) => $page->where('open', false));
    $this->from(sectionUrl($this->subject))
        ->put(sectionUrl($this->subject), ['marks' => [$this->students[0]->uuid => '50']])
        ->assertSessionHasErrors(['marks' => 'Mark entry is closed for this period.']);
});

it('reads a section in the same few queries however many students it has', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(sectionUrl($this->subject))->assertOk();

        return count(DB::getQueryLog());
    };

    $few = $count();
    $students = Student::factory()->count(40)->create();
    app(SaveMarks::class)->handle(
        $this->teacher,
        $this->subject,
        Section::A,
        Period::Month1,
        $students->mapWithKeys(fn (Student $s) => [$s->uuid => Mark::of(60)])->all(),
    );

    expect($count())->toBe($few)->toBeLessThanOrEqual(8);
});
