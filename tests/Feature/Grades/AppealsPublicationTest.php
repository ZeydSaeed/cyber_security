<?php

use App\Modules\Grades\Application\Actions\ChangeEntryWindow;
use App\Modules\Grades\Application\Actions\ManageAccess;
use App\Modules\Grades\Application\Actions\ManageAppeals;
use App\Modules\Grades\Application\Actions\ManageResults;
use App\Modules\Grades\Application\Actions\PublishResults;
use App\Modules\Grades\Application\Actions\RequestEdit;
use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Application\Jobs\NotifyGradesManagers;
use App\Modules\Grades\Application\StudentCodes;
use App\Modules\Grades\Domain\AppealStatus;
use App\Modules\Grades\Domain\GradeBands;
use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\ResultDisplay;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\TeacherAccess;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\GradeAppeal;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditEvent;
use App\Platform\Exceptions\DomainRuleViolation;
use App\Platform\Outbox\OutboxMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Grades\GradesFixtures;

/*
| The owner's last approved suggestions (ADR-0042): students appeal a mark
| within days of publication; results publish one section at a time; the
| department hears of requests and appeals; a frequently absent student
| is flagged; a teacher is warned before marks lock.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    RateLimiter::clear('grades-student:minute:127.0.0.1');
    RateLimiter::clear('grades-teacher:minute:127.0.0.1');
    $this->owner = GradesFixtures::owner();
    $this->teacher = Teacher::factory()->withCode('river-stone-81')->create(['name' => 'Huda Karim']);
    $this->networks = Subject::factory()->stage(Stage::Second)->create(['name' => 'Networks', 'sort_order' => 1, 'teacher_id' => $this->teacher->id]);
    $this->crypto = Subject::factory()->stage(Stage::Second)->create(['name' => 'Crypto', 'sort_order' => 2, 'teacher_id' => $this->teacher->id]);
    $this->ali = Student::factory()->named('Ali Hassan')->in(Stage::Second, Section::A)->create();
    $this->bilal = Student::factory()->named('Bilal Nouri')->in(Stage::Second, Section::B)->create();
    $codes = app(StudentCodes::class);
    foreach ([[$this->ali, 'AAAA-BBBB'], [$this->bilal, 'CCCC-DDDD']] as [$student, $code]) {
        $student->forceFill(['code_digest' => $codes->digest($code), 'code_encrypted' => $codes->encrypt($code)])->save();
    }

    GradesFixtures::openWindow();
    $save = fn (Subject $subject, Section $section, array $marks) => app(SaveMarks::class)->handle($this->owner, $subject, $section, Period::Month1, $marks);
    $save($this->networks, Section::A, [$this->ali->uuid => Mark::of(55)]);
    $save($this->crypto, Section::A, [$this->ali->uuid => Mark::absent()]);
    $save($this->networks, Section::B, [$this->bilal->uuid => Mark::of(70)]);
});

function resultExtras(object $test, ?int $appealDays, ?int $absenceFlag): void
{
    app(ManageResults::class)->settings($test->owner, 50, null, null, GradeBands::DEFAULTS, ResultDisplay::OPTIONS, ResultDisplay::TONED, $appealDays, $absenceFlag);
}

function closeEntry(object $test): void
{
    app(ChangeEntryWindow::class)->handle($test->owner, Period::Month1, WindowStatus::Closed);
}

describe('publishing one section', function () {
    it('shows one section its results and keeps the other waiting', function () {
        closeEntry($this);
        app(PublishResults::class)->section($this->owner, Period::Month1, Stage::Second, Section::A, true);

        $this->post('/student', ['code' => 'AAAA-BBBB']);
        $this->get('/student/result')->assertInertia(fn (Assert $page) => $page->where('published', true));
        $this->post('/student/logout');

        $this->post('/student', ['code' => 'CCCC-DDDD']);
        $this->get('/student/result')->assertInertia(fn (Assert $page) => $page->where('published', false)->where('card', null));

        expect(AuditEvent::query()->where('action', 'grades.section_published')->sole()->metadata)->toEqual(['stage' => 2, 'section' => 'A']);
    });

    it('needs entry closed, refuses reopening entry while a section is shown, and hiding all hides it', function () {
        expect(fn () => app(PublishResults::class)->section($this->owner, Period::Month1, Stage::Second, Section::A, true))
            ->toThrow(DomainRuleViolation::class, 'Close mark entry before publishing the results.');

        closeEntry($this);
        app(PublishResults::class)->section($this->owner, Period::Month1, Stage::Second, Section::A, true);
        expect(fn () => app(ChangeEntryWindow::class)->handle($this->owner, Period::Month1, WindowStatus::Open))
            ->toThrow(DomainRuleViolation::class, 'Hide the published results before opening mark entry again.');

        app(PublishResults::class)->handle($this->owner, Period::Month1, true);
        app(PublishResults::class)->handle($this->owner, Period::Month1, false);
        $this->actingAs($this->owner)->get('/admin')->assertInertia(fn (Assert $page) => $page
            ->where('window.published', false)
            ->where('window.sections_published', []));
    });

    it('publishes from the overview', function () {
        closeEntry($this);
        $this->actingAs($this->owner)->put('/admin/publish/section', ['stage' => 2, 'section' => 'B', 'published' => true])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->get('/admin')->assertInertia(fn (Assert $page) => $page->where('window.sections_published', ['2|B']));
    });
});

describe('appeals', function () {
    beforeEach(function () {
        resultExtras($this, 3, null);
        closeEntry($this);
        app(PublishResults::class)->section($this->owner, Period::Month1, Stage::Second, Section::A, true);
        $this->post('/student', ['code' => 'AAAA-BBBB']);
    });

    it('lets a student appeal a mark within the days allowed, once at a time, and tells the department', function () {
        $this->get('/student/result')->assertInertia(fn (Assert $page) => $page->whereNot('appealUntil', null)->where('appeals', []));

        $this->from('/student/result')->post('/student/appeals', ['subject' => $this->networks->uuid, 'reason' => 'My answer to question 3 was right.'])
            ->assertRedirect('/student/result');
        $this->from('/student/result')->post('/student/appeals', ['subject' => $this->networks->uuid, 'reason' => 'Again, please.'])
            ->assertSessionHasErrors(['subject' => 'Your appeal of this subject is still waiting for an answer.']);

        $appeal = GradeAppeal::query()->sole();
        expect($appeal->status)->toBe(AppealStatus::Pending)
            ->and(AuditEvent::query()->where('action', 'grades.appeal_submitted')->sole()->actor_id)->toBeNull()
            ->and(OutboxMessage::query()->where('topic', NotifyGradesManagers::TOPIC)->sole()->payload['kind'])->toBe('grades_appeal_submitted');

        // The teacher sees it on the sheet; the owner on the overview.
        $this->post('/teacher', ['code' => 'river-stone-81']);
        $this->get("/teacher/subjects/{$this->networks->uuid}/A")->assertInertia(fn (Assert $page) => $page
            ->where('appeals.0.student', 'Ali Hassan')
            ->where('appeals.0.status', 'pending'));
        $this->actingAs($this->owner)->get('/admin')->assertInertia(fn (Assert $page) => $page
            ->where('access.appeals.0.student', 'Ali Hassan')
            ->where('access.appeals.0.subject', 'Networks'));
    });

    it('closes appeals after the days allowed', function () {
        $this->travel(4)->days();
        // The session ended meanwhile (30 idle minutes): sign in again.
        $this->post('/student', ['code' => 'AAAA-BBBB']);

        $this->from('/student/result')->post('/student/appeals', ['subject' => $this->networks->uuid, 'reason' => 'My answer was right.'])
            ->assertSessionHasErrors(['reason' => 'Appeals are closed.']);
    });

    it('refuses a subject of another stage', function () {
        $other = Subject::factory()->create(['name' => 'First-stage subject']);

        expect(fn () => app(ManageAppeals::class)->submit($this->ali, $other, Period::Month1, 'Please check.'))
            ->toThrow(DomainRuleViolation::class, 'Choose a subject of yours that has a mark.');
    });

    it('lets the owner answer once, and the student reads the answer', function () {
        $appeal = app(ManageAppeals::class)->submit($this->ali, $this->networks, Period::Month1, 'Please check question 3.');

        $this->actingAs($this->owner)->put("/admin/appeals/{$appeal->uuid}", ['decision' => 'accept', 'response' => 'Corrected to 60.'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->put("/admin/appeals/{$appeal->uuid}", ['decision' => 'reject'])
            ->assertSessionHasErrors(['appeal' => 'This appeal has already been answered.']);

        $this->get('/student/result')->assertInertia(fn (Assert $page) => $page
            ->where('appeals.0.status', 'accepted')
            ->where('appeals.0.response', 'Corrected to 60.'));
    });
});

it('tells the department of a teacher\'s request to change marks', function () {
    closeEntry($this);
    app(RequestEdit::class)->handle($this->teacher, $this->networks, Section::A, Period::Month1, 'A typing mistake.');

    $payload = OutboxMessage::query()->where('topic', NotifyGradesManagers::TOPIC)->sole()->payload;
    expect($payload['kind'])->toBe('grades_edit_requested')
        ->and($payload['facts'])->toEqual(['teacher' => 'Huda Karim', 'subject' => 'Networks', 'stage' => '2', 'section' => 'A']);
});

it('flags a student absent from as many subjects as the owner sets, not on the student\'s card', function () {
    resultExtras($this, null, 1);

    $this->actingAs($this->owner)->get('/admin/results?stage=2&section=A')->assertInertia(fn (Assert $page) => $page
        ->where('absence_flag', 1)
        ->where('students.0.frequent_absence', true));

    closeEntry($this);
    app(PublishResults::class)->handle($this->owner, Period::Month1, true);
    $this->post('/student', ['code' => 'AAAA-BBBB']);
    $this->get('/student/result')->assertInertia(fn (Assert $page) => $page->missing('card.absence_flag'));
});

it('warns a teacher of marks about to lock', function () {
    app(ManageAccess::class)->teacher($this->owner, $this->teacher, TeacherAccess::Timed, 20, null);
    app(SaveMarks::class)->handle($this->teacher->fresh(), $this->networks, Section::B, Period::Month1, [$this->bilal->uuid => Mark::of(71)]);

    $this->post('/teacher', ['code' => 'river-stone-81']);
    $this->get("/teacher/subjects/{$this->networks->uuid}/B")->assertInertia(fn (Assert $page) => $page
        ->whereNot('students.0.locks_at', null));
});
