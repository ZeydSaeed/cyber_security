<?php

use App\Modules\Grades\Application\Actions\ChangeEntryWindow;
use App\Modules\Grades\Application\Actions\ManageAppeals;
use App\Modules\Grades\Application\Actions\ManageResults;
use App\Modules\Grades\Application\Actions\PublishResults;
use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Application\Jobs\NotifyGradesManagers;
use App\Modules\Grades\Application\Notifications\GradesNotification;
use App\Modules\Grades\Application\Queries\MarkHistory;
use App\Modules\Grades\Application\StudentCodes;
use App\Modules\Grades\Domain\AppealScope;
use App\Modules\Grades\Domain\AppealStatus;
use App\Modules\Grades\Domain\GradeBands;
use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\ResultDisplay;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\GradeAppeal;
use App\Modules\Grades\Models\GradeSettings;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\SubjectTeacher;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditEvent;
use App\Platform\Exceptions\DomainRuleViolation;
use App\Platform\Outbox\OutboxMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Grades\GradesFixtures;

/*
| The owner's requests of 2026-11-19, second round (ADR-0044): what the
| student sees and the appeals, saved from the "Students" box of the
| settings; teachers answering appeals of their own subjects when the
| owner allows it, with the department told; each teacher's count of
| waiting appeals; and, on the owner's page of one student, each mark's
| journey and the student's appeals.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['teacher', 'student'] as $who) {
        RateLimiter::clear("grades-{$who}:minute:127.0.0.1");
    }

    $this->owner = GradesFixtures::owner();
    $this->teacher = Teacher::factory()->withCode('river-stone-81')->create(['name' => 'Huda Karim']);
    $this->other = Teacher::factory()->withCode('lake-cloud-42')->create(['name' => 'Omar Saleh']);
    $this->networks = Subject::factory()->stage(Stage::Second)->create(['name' => 'Networks', 'teacher_id' => $this->teacher->id]);
    // Section B of the same subject has the other teacher.
    SubjectTeacher::query()->where('subject_id', $this->networks->id)->where('section', 'B')->update(['teacher_id' => $this->other->id]);
    $this->ali = Student::factory()->named('Ali Hassan')->in(Stage::Second, Section::A)->create();
    $codes = app(StudentCodes::class);
    $this->ali->forceFill(['code_digest' => $codes->digest('AAAA-BBBB'), 'code_encrypted' => $codes->encrypt('AAAA-BBBB')])->save();

    GradesFixtures::openWindow();
    app(SaveMarks::class)->handle($this->teacher, $this->networks, Section::A, Period::Month1, [$this->ali->uuid => Mark::of(48)]);
    app(SaveMarks::class)->handle($this->owner, $this->networks, Section::A, Period::Month1, [$this->ali->uuid => Mark::of(52)]);
    app(ManageResults::class)->settings($this->owner, 50, null, null, GradeBands::DEFAULTS, ResultDisplay::OPTIONS, ResultDisplay::TONED, 1440);
    app(ChangeEntryWindow::class)->handle($this->owner, Period::Month1, WindowStatus::Closed);
    app(PublishResults::class)->handle($this->owner, Period::Month1, true);
    $this->appeal = app(ManageAppeals::class)->submit($this->ali, $this->networks, Period::Month1, 'Question 3 was right.');
});

function allowTeacherReplies(object $test, bool $allowed = true): void
{
    app(ManageResults::class)->studentView($test->owner, ResultDisplay::OPTIONS, 1440, null, AppealScope::All, $allowed);
}

describe('the "Students" box', function () {
    it('saves what the student sees on its own, and the result rules keep it', function () {
        $this->actingAs($this->owner)->put('/admin/results/student-view', [
            'options' => ['total', 'absences', 'appeals'],
            'appeal_hours' => 6,
            'appeal_scope' => 'selected',
            'teacher_appeal_replies' => '1',
        ])->assertSessionHasNoErrors();

        $settings = GradeSettings::current();
        expect($settings->options()['absences'])->toBeTrue()
            ->and($settings->options()['percentage'])->toBeFalse()
            ->and($settings->appeal_minutes)->toBe(360)
            ->and($settings->appeal_scope)->toBe(AppealScope::Selected)
            ->and($settings->teacher_appeal_replies)->toBeTrue()
            ->and(AuditEvent::query()->where('action', 'grades.student_view_changed')->sole()->actor_id)->toBe($this->owner->id);

        // The result rules' form no longer sends the card's parts.
        $this->actingAs($this->owner)->put('/admin/results/settings', [
            'pass_percent' => 60,
            'bands' => GradeBands::DEFAULTS,
            'tones' => ResultDisplay::TONED,
        ])->assertSessionHasNoErrors();

        $settings = GradeSettings::current();
        expect($settings->pass_percent)->toBe(60)
            ->and($settings->options()['absences'])->toBeTrue()
            ->and($settings->appeal_minutes)->toBe(360)
            ->and($settings->appeal_scope)->toBe(AppealScope::Selected);

        $this->actingAs($this->owner)->get('/admin')->assertInertia(fn (Assert $page) => $page
            ->where('resultSettings.teacher_appeal_replies', true)
            ->where('resultSettings.options.absences', true));
    });
});

describe('teachers answering appeals', function () {
    it('refuses unless the owner allows it', function () {
        $this->post('/teacher', ['code' => 'river-stone-81']);
        $this->get("/teacher/subjects/{$this->networks->uuid}/A")->assertInertia(fn (Assert $page) => $page
            ->where('appeals.0.uuid', $this->appeal->uuid)
            ->where('appeals.0.can_answer', false));

        $this->from("/teacher/subjects/{$this->networks->uuid}/A")
            ->put("/teacher/appeals/{$this->appeal->uuid}", ['decision' => 'accept'])
            ->assertSessionHasErrors(['appeal' => 'The department answers the appeals.']);
    });

    it('lets the subject\'s teacher answer once, tells the department, and the student reads it', function () {
        allowTeacherReplies($this);
        $this->post('/teacher', ['code' => 'river-stone-81']);
        $this->get("/teacher/subjects/{$this->networks->uuid}/A")->assertInertia(fn (Assert $page) => $page->where('appeals.0.can_answer', true));

        $this->from("/teacher/subjects/{$this->networks->uuid}/A")
            ->put("/teacher/appeals/{$this->appeal->uuid}", ['decision' => 'reject', 'response' => 'Question 3 lacked the steps.'])
            ->assertSessionHasNoErrors();

        $appeal = $this->appeal->fresh();
        $audit = AuditEvent::query()->where('action', 'grades.appeal_decided')->sole();
        $payload = OutboxMessage::query()->where('topic', NotifyGradesManagers::TOPIC)->latest('id')->first()?->payload;
        expect($appeal->status)->toBe(AppealStatus::Rejected)
            ->and($appeal->decided_by_teacher_id)->toBe($this->teacher->id)
            ->and($appeal->decided_by_user_id)->toBeNull()
            ->and($audit->actor_id)->toBeNull()
            ->and($audit->metadata['teacher_name'])->toBe('Huda Karim')
            ->and($payload['kind'])->toBe(GradesNotification::APPEAL_ANSWERED)
            ->and($payload['facts'])->toEqual(['teacher' => 'Huda Karim', 'student' => 'Ali Hassan', 'subject' => 'Networks', 'decision' => 'rejected']);

        expect(fn () => app(ManageAppeals::class)->answerAsTeacher($this->teacher, $appeal, true, null))
            ->toThrow(DomainRuleViolation::class, 'This appeal has already been answered.');

        $this->post('/student', ['code' => 'AAAA-BBBB']);
        $this->get('/student/result')->assertInertia(fn (Assert $page) => $page
            ->where('appeals.0.status', 'rejected')
            ->where('appeals.0.response', 'Question 3 lacked the steps.'));
    });

    it('refuses a teacher of another section', function () {
        allowTeacherReplies($this);

        expect(fn () => app(ManageAppeals::class)->answerAsTeacher($this->other, $this->appeal, true, null))
            ->toThrow(DomainRuleViolation::class, 'This appeal is not of a section you teach.');
        expect($this->appeal->fresh()->status)->toBe(AppealStatus::Pending);
    });

    it('counts each section\'s waiting appeals for its teacher', function () {
        $this->post('/teacher', ['code' => 'river-stone-81']);
        $this->get('/teacher/subjects')->assertInertia(fn (Assert $page) => $page
            ->where('subjects.0.sections.0.section', 'A')
            ->where('subjects.0.sections.0.appeals', 1));
    });
});

describe('the owner\'s page of one student', function () {
    it('shows each mark\'s journey, the appeals, and whether the student may appeal', function () {
        app(ManageResults::class)->studentView($this->owner, ResultDisplay::OPTIONS, 1440, null, AppealScope::Selected, false);

        $this->actingAs($this->owner)->get("/admin/results/students/{$this->ali->uuid}")->assertInertia(fn (Assert $page) => $page
            ->has('journeys', 2)
            ->where('journeys.0.subject', 'Networks')
            ->where('journeys.0.from', null)
            ->where('journeys.0.to', 48)
            ->where('journeys.0.by', 'Huda Karim')
            ->where('journeys.1.from', 48)
            ->where('journeys.1.to', 52)
            ->where('journeys.1.owner', true)
            ->where('appeals.0.status', 'pending')
            ->where('appeals.0.subject', 'Networks')
            ->where('appealAccess.scope', 'selected')
            ->where('appealAccess.allowed', false));
    });

    it('reads only the records that touch the student', function () {
        $bilal = Student::factory()->named('Bilal Nouri')->in(Stage::Second, Section::A)->create();
        app(SaveMarks::class)->handle($this->owner, $this->networks, Section::A, Period::Month1, [$bilal->uuid => Mark::of(70)]);

        expect(app(MarkHistory::class)->forStudent($bilal))->toHaveCount(1)
            ->and(app(MarkHistory::class)->forStudent($this->ali))->toHaveCount(2);
    });
});

it('keeps the appeal for the department when the teacher may not answer', function () {
    expect(GradeAppeal::query()->sole()->status)->toBe(AppealStatus::Pending);
    $this->actingAs($this->owner)->get('/admin')->assertInertia(fn (Assert $page) => $page->where('access.appeals.0.student', 'Ali Hassan'));
});
