<?php

use App\Modules\Grades\Application\Actions\ChangeEntryWindow;
use App\Modules\Grades\Application\Actions\ManageAccess;
use App\Modules\Grades\Application\Actions\ManageAppeals;
use App\Modules\Grades\Application\Actions\ManageResults;
use App\Modules\Grades\Application\Actions\PublishResults;
use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Domain\AppealScope;
use App\Modules\Grades\Domain\AppealStatus;
use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\ResultDisplay;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\StudentAccess;
use App\Modules\Grades\Domain\TeacherAccess;
use App\Modules\Grades\Domain\TeacherResults;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\GradeAppeal;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\GradeSettings;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\SubjectTeacher;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditEvent;
use App\Platform\Exceptions\DomainRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Grades\GradesFixtures;

/*
| Findings of the full data-flow audit (owner's request, 2026-11-20):
| every setting of the administration, subjects, teachers and students
| tried in turn; each defect found is fixed and pinned here.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    RateLimiter::clear('grades-teacher:minute:127.0.0.1');
    $this->owner = GradesFixtures::owner();
    $this->teacher = Teacher::factory()->withCode('river-stone-81')->create(['name' => 'Huda Karim']);
    $this->other = Teacher::factory()->create(['name' => 'Omar Salim']);
    // Networks: Huda teaches section A, Omar section B. Crypto: Omar's.
    $this->networks = Subject::factory()->stage(Stage::Second)->create(['name' => 'Networks', 'sort_order' => 1, 'teacher_id' => $this->other->id]);
    SubjectTeacher::query()->where('subject_id', $this->networks->id)->where('section', 'A')->update(['teacher_id' => $this->teacher->id]);
    $this->crypto = Subject::factory()->stage(Stage::Second)->create(['name' => 'Crypto', 'sort_order' => 2, 'teacher_id' => $this->other->id]);
    $this->ali = Student::factory()->named('Ali Hassan')->in(Stage::Second, Section::A)->create();
    $this->sara = Student::factory()->named('Sara Adel')->in(Stage::Second, Section::A)->create();

    GradesFixtures::openWindow();
    $save = fn (Subject $subject, array $marks) => app(SaveMarks::class)->handle($this->owner, $subject, Section::A, Period::Month1, $marks);
    $save($this->networks, [$this->ali->uuid => Mark::of(80), $this->sara->uuid => Mark::of(70)]);
    // Ali was absent from Crypto and failed nothing he sat; Sara has no Crypto mark yet.
    $save($this->crypto, [$this->ali->uuid => Mark::absent()]);
});

function flowPublish(object $test): void
{
    app(ChangeEntryWindow::class)->handle($test->owner, Period::Month1, WindowStatus::Closed);
    app(PublishResults::class)->handle($test->owner, Period::Month1, true);
}

it('lets no teacher answer an appeal of a section blocked for them', function () {
    app(ManageResults::class)->appeals($this->owner, true, 1440, null, null, AppealScope::All, true);
    flowPublish($this);
    $appeal = app(ManageAppeals::class)->submit($this->ali, $this->networks, Period::Month1, 'Please check my paper again.');

    app(ManageAccess::class)->section($this->owner, $this->networks, Section::A, TeacherAccess::Blocked, 'Moved', null);
    $this->post('/teacher', ['code' => 'river-stone-81']);
    $this->put("/teacher/appeals/{$appeal->uuid}", ['decision' => 'accept'])->assertSessionHasErrors('appeal');
    expect($appeal->fresh()->status)->toBe(AppealStatus::Pending);

    // The section given back: the answer goes through.
    app(ManageAccess::class)->section($this->owner, $this->networks, Section::A, null, null, null);
    $this->put("/teacher/appeals/{$appeal->uuid}", ['decision' => 'reject', 'response' => 'Checked.'])->assertSessionHasNoErrors();
    expect($appeal->fresh()->status)->toBe(AppealStatus::Rejected)
        ->and($appeal->fresh()->decided_by_teacher_id)->toBe($this->teacher->id);
});

it('tells a teacher nothing of the subjects they may not see', function () {
    app(ManageResults::class)->teacherView($this->owner, TeacherResults::Always, [...ResultDisplay::TEACHER_OPTIONS, 'other_subjects' => false]);
    app(ManageResults::class)->settings($this->owner, 50, null, null, GradeSettings::current()->bands()->minimums, GradeSettings::current()->options(), GradeSettings::current()->tones(), null, 1);
    $this->post('/teacher', ['code' => 'river-stone-81']);

    $this->get('/teacher/results?stage=2&section=A')->assertInertia(fn (Assert $page) => $page
        ->where('allowed', true)
        ->has('sheet.subjects', 1)
        ->where('sheet.subjects.0.name', 'Networks')
        // Counted over Networks only: Ali's Crypto absence and Sara's
        // missing Crypto mark are not told.
        ->where('sheet.students.0.name', 'Ali Hassan')
        ->where('sheet.students.0.absences', 0)
        ->where('sheet.students.0.missing', 0)
        ->where('sheet.students.0.complete', true)
        ->where('sheet.students.0.passed', true)
        ->where('sheet.students.0.frequent_absence', false)
        ->where('sheet.students.1.missing', 0)
        ->where('sheet.summary.complete', 2));

    // With the other subjects shown, the whole picture.
    app(ManageResults::class)->teacherView($this->owner, TeacherResults::Always, [...ResultDisplay::TEACHER_OPTIONS, 'other_subjects' => true]);
    $this->get('/teacher/results?stage=2&section=A')->assertInertia(fn (Assert $page) => $page
        ->has('sheet.subjects', 2)
        ->where('sheet.students.0.absences', 1)
        ->where('sheet.students.0.frequent_absence', true)
        ->where('sheet.students.1.missing', 1)
        ->where('sheet.summary.complete', 1));
});

it('keeps a section\'s own publication moment when everything is published later', function () {
    $this->freezeSecond();
    app(ManageResults::class)->appeals($this->owner, true, 60, null, null, AppealScope::All, false);
    app(ChangeEntryWindow::class)->handle($this->owner, Period::Month1, WindowStatus::Closed);
    app(PublishResults::class)->section($this->owner, Period::Month1, Stage::Second, Section::A, true);
    $first = CarbonImmutable::now();

    $this->travel(2)->hours();
    app(PublishResults::class)->handle($this->owner, Period::Month1, true);

    $window = GradePeriod::for(Period::Month1);
    expect($window->publishedAtFor(Stage::Second, Section::A)?->toIso8601String())->toBe($first->toIso8601String())
        // Section B was published only now.
        ->and($window->publishedAtFor(Stage::Second, Section::B)?->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String())
        // Section A's hour of appeals ended an hour ago: it does not start again.
        ->and(ManageAppeals::openUntil($this->ali->fresh(), GradeSettings::current(), $window))->toBeNull();
});

it('refuses the limited-time mode where no editing time applies', function () {
    $access = app(ManageAccess::class);

    expect(fn () => $access->teacher($this->owner, $this->teacher, TeacherAccess::Timed, null, null))
        ->toThrow(DomainRuleViolation::class, 'Give the editing time, or the date it ends, for the limited-time mode.');
    expect(fn () => $access->section($this->owner, $this->networks, Section::A, TeacherAccess::Timed, null, null))
        ->toThrow(DomainRuleViolation::class, 'This section\'s teacher has no editing time, nor has the department. Give one first.');

    // With the teacher's own time, both are accepted.
    $access->teacher($this->owner, $this->teacher, TeacherAccess::Timed, 90, null);
    $access->section($this->owner, $this->networks, Section::A, TeacherAccess::Timed, null, null);
    expect($this->teacher->fresh()->access_mode)->toBe(TeacherAccess::Timed);

    // A department time that a limited-time section of Omar's relies on cannot be cleared.
    $settings = fn (?int $minutes): array => [
        'teacher_attempts_per_minute' => 5, 'teacher_attempts_per_hour' => 20,
        'student_attempts_per_minute' => 5, 'student_attempts_per_hour' => 20,
        'teacher_max_logins' => null, 'student_max_logins' => null,
        'teacher_mode' => TeacherAccess::Edit, 'teacher_edit_minutes' => $minutes, 'teacher_edit_until' => null,
        'student_access' => StudentAccess::Allowed, 'remember_days' => 30,
    ];
    $access->settings($this->owner, $settings(120));
    $access->section($this->owner, $this->crypto, Section::A, TeacherAccess::Timed, null, null);
    expect(fn () => $access->settings($this->owner, $settings(null)))
        ->toThrow(DomainRuleViolation::class, 'Some teachers or sections are in the limited-time mode with no time of their own. Give them one, or keep a time here.');
    expect(GradeSettings::current()->teacher_edit_minutes)->toBe(120);
});

it('hides the sections published one by one when everything is hidden', function () {
    app(ChangeEntryWindow::class)->handle($this->owner, Period::Month1, WindowStatus::Closed);
    app(PublishResults::class)->section($this->owner, Period::Month1, Stage::Second, Section::A, true);

    $this->actingAs($this->owner)->put('/admin/publish', ['published' => false])->assertSessionHasNoErrors();

    expect(GradePeriod::for(Period::Month1)->anyPublished())->toBeFalse();
    // Nothing out: hiding again records nothing more.
    $hidden = fn () => AuditEvent::query()->where('action', 'grades.results_unpublished')->count();
    expect($hidden())->toBe(1);
    app(PublishResults::class)->handle($this->owner, Period::Month1, false);
    expect($hidden())->toBe(1);
});

it('refuses appeals allowed with no time and no closing date', function () {
    $this->actingAs($this->owner)->put('/admin/results/appeals', [
        'enabled' => '1',
        'appeal_scope' => 'all',
        'answered_by' => 'department',
    ])->assertSessionHasErrors(['appeal_minutes' => 'Give how long appeals stay open, or the date they close.']);

    // Off, they need neither.
    $this->put('/admin/results/appeals', ['appeal_scope' => 'all', 'answered_by' => 'department'])->assertSessionHasNoErrors();
    expect(GradeSettings::current()->options()['appeals'])->toBeFalse();
    expect(GradeAppeal::query()->count())->toBe(0);
});
