<?php

use App\Models\User;
use App\Modules\Grades\Application\Actions\ChangeEntryWindow;
use App\Modules\Grades\Application\Actions\ManageAccess;
use App\Modules\Grades\Application\Actions\ManageResults;
use App\Modules\Grades\Application\Actions\PublishResults;
use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Domain\Behaviour;
use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\ResultDisplay;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\TeacherAccess;
use App\Modules\Grades\Domain\TeacherResults;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\SubjectTeacher;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Grades\GradesFixtures;

/*
| A teacher reads their own students' results (ADR-0040): only the sections
| they teach, only when the owner allows, and only what the owner chose;
| hidden parts never reach the page.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    RateLimiter::clear('grades-teacher:minute:127.0.0.1');
    $this->owner = GradesFixtures::owner();
    $this->teacher = Teacher::factory()->withCode('river-stone-81')->create(['name' => 'Huda Karim']);
    $this->other = Teacher::factory()->create(['name' => 'Omar Salim']);

    // Second stage: the teacher has Networks in section A only; Crypto is another teacher's.
    $this->networks = Subject::factory()->stage(Stage::Second)->create(['name' => 'Networks', 'sort_order' => 1, 'teacher_id' => $this->other->id]);
    SubjectTeacher::query()->where('subject_id', $this->networks->id)->where('section', 'A')->update(['teacher_id' => $this->teacher->id]);
    $this->crypto = Subject::factory()->stage(Stage::Second)->create(['name' => 'Crypto', 'sort_order' => 2, 'teacher_id' => $this->other->id]);

    $this->ali = Student::factory()->named('Ali Hassan')->in(Stage::Second, Section::A)->create();
    $this->bilal = Student::factory()->named('Bilal Nouri')->in(Stage::Second, Section::B)->create();

    GradesFixtures::openWindow();
    $save = fn (Subject $subject, Section $section, array $marks) => app(SaveMarks::class)->handle($this->owner, $subject, $section, Period::Month1, $marks);
    $save($this->networks, Section::A, [$this->ali->uuid => Mark::of(80)]);
    $save($this->crypto, Section::A, [$this->ali->uuid => Mark::of(40)]);
    $save($this->networks, Section::B, [$this->bilal->uuid => Mark::of(90)]);
    app(ManageResults::class)->note($this->owner, $this->ali, Period::Month1, Behaviour::Good, 'Late twice.');

    $this->post('/teacher', ['code' => 'river-stone-81']);
});

function teacherView(object $test, TeacherResults $access, array $options = []): void
{
    app(ManageResults::class)->teacherView($test->owner, $access, [...ResultDisplay::TEACHER_OPTIONS, ...$options]);
}

it('shows nothing until the owner allows it', function () {
    $this->get('/teacher/results')->assertInertia(fn (Assert $page) => $page
        ->component('modules/grades/pages/teacher/results')
        ->where('allowed', false)
        ->where('sheet', null));

    expect(AuditEvent::query()->where('action', 'grades.teacher_results_viewed')->count())->toBe(0);
});

it('waits for publication when the owner says so', function () {
    teacherView($this, TeacherResults::Published);
    $this->get('/teacher/results')->assertInertia(fn (Assert $page) => $page->where('allowed', false));

    app(ChangeEntryWindow::class)->handle($this->owner, Period::Month1, WindowStatus::Closed);
    app(PublishResults::class)->handle($this->owner, Period::Month1, true);
    $this->get('/teacher/results')->assertInertia(fn (Assert $page) => $page->where('allowed', true)->has('sheet.students', 1));
});

it('shows only the teacher\'s sections and subjects, and none of what is hidden', function () {
    teacherView($this, TeacherResults::Always);

    $this->get('/teacher/results')->assertInertia(fn (Assert $page) => $page
        ->where('allowed', true)
        // Section A only: section B's Networks is the other teacher's.
        ->has('places', 1)
        ->where('places.0.section', 'A')
        ->where('sheet.section', 'A')
        // Their own subject only; Crypto's 40 is not sent.
        ->has('sheet.subjects', 1)
        ->where('sheet.subjects.0.name', 'Networks')
        ->has('sheet.students', 1)
        ->where('sheet.students.0.name', 'Ali Hassan')
        ->where('sheet.students.0.marks', [80])
        // The defaults: total and percentage, not rank, behaviour or notes.
        ->where('sheet.students.0.total', 120)
        ->where('sheet.students.0.rank', null)
        ->where('sheet.students.0.behaviour', null)
        ->where('sheet.students.0.notes', null));

    $this->get('/teacher/results?stage=2&section=B')->assertNotFound();

    $audit = AuditEvent::query()->where('action', 'grades.teacher_results_viewed')->sole();
    expect($audit->actor_id)->toBeNull()
        ->and($audit->metadata)->toBe(['stage' => 2, 'section' => 'A']);
});

it('shows what the owner ticks', function () {
    teacherView($this, TeacherResults::Always, ['other_subjects' => true, 'rank' => true, 'behaviour' => true, 'notes' => true, 'total' => false]);

    $this->get('/teacher/results')->assertInertia(fn (Assert $page) => $page
        ->has('sheet.subjects', 2)
        ->where('sheet.students.0.marks', [80, 40])
        ->where('sheet.students.0.rank', 1)
        ->where('sheet.students.0.behaviour', 'good')
        ->where('sheet.students.0.notes', 'Late twice.')
        ->where('sheet.students.0.total', null)
        ->where('sheet.view.rank', true));
});

it('drops a section blocked for the teacher', function () {
    teacherView($this, TeacherResults::Always);
    app(ManageAccess::class)->section($this->owner, $this->networks, Section::A, TeacherAccess::Blocked, null, null);

    $this->get('/teacher/results')->assertInertia(fn (Assert $page) => $page->has('places', 0)->where('sheet', null));
});

it('saves what teachers see from the owner\'s page, audited, and refuses others', function () {
    $this->actingAs($this->owner)->put('/admin/results/teacher-view', [
        'teacher_results' => 'always',
        'teacher_view' => ['rank', 'notes'],
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->owner)->get('/admin')->assertInertia(fn (Assert $page) => $page
        ->where('resultSettings.teacher_results', 'always')
        ->where('resultSettings.teacher_view.rank', true)
        ->where('resultSettings.teacher_view.total', false));
    expect(AuditEvent::query()->where('action', 'grades.teacher_view_changed')->count())->toBe(1);

    $this->actingAs($this->owner)->put('/admin/results/teacher-view', ['teacher_results' => 'sometimes'])
        ->assertSessionHasErrors('teacher_results');
    $this->actingAs(User::factory()->withTwoFactor()->create())->put('/admin/results/teacher-view', ['teacher_results' => 'off'])
        ->assertForbidden();
});
