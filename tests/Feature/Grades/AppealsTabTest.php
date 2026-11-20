<?php

use App\Modules\Grades\Application\Actions\ChangeEntryWindow;
use App\Modules\Grades\Application\Actions\ManageAppeals;
use App\Modules\Grades\Application\Actions\ManageResults;
use App\Modules\Grades\Application\Actions\PublishResults;
use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Domain\AppealScope;
use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\GradeSettings;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditEvent;
use App\Platform\Exceptions\DomainRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Grades\GradesFixtures;

/*
| Students' appeals on a settings tab of their own (owner's request,
| 2026-11-20): on or off, who answers (the subject's teacher or the
| administration), open for days, hours or minutes, from and until a
| date, and the appeals counted by status for the summary.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = GradesFixtures::owner();
    $this->teacher = Teacher::factory()->withCode('river-stone-81')->create(['name' => 'Huda Karim']);
    $this->subject = Subject::factory()->stage(Stage::Second)->create(['name' => 'Networks', 'teacher_id' => $this->teacher->id]);
    $this->ali = Student::factory()->named('Ali Hassan')->in(Stage::Second, Section::A)->create();
    GradesFixtures::openWindow();
    app(SaveMarks::class)->handle($this->teacher, $this->subject, Section::A, Period::Month1, [$this->ali->uuid => Mark::of(48)]);

    $this->appealSettings = fn (bool $enabled, ?int $minutes, ?CarbonImmutable $from = null, ?CarbonImmutable $until = null, bool $teacher = false) => app(ManageResults::class)
        ->appeals($this->owner, $enabled, $minutes, $from, $until, AppealScope::All, $teacher);
    $this->publish = function (): void {
        app(ChangeEntryWindow::class)->handle($this->owner, Period::Month1, WindowStatus::Closed);
        app(PublishResults::class)->handle($this->owner, Period::Month1, true);
    };
    $this->openUntil = fn () => ManageAppeals::openUntil($this->ali->fresh(), GradeSettings::current(), GradePeriod::for(Period::Month1));
});

it('saves the appeal settings from the tab, and audits them', function () {
    $this->actingAs($this->owner)->put('/admin/results/appeals', [
        'enabled' => '1',
        'appeal_days' => 2,
        'appeal_hours' => 3,
        'appeal_from' => '2026-12-01T08:00:00.000Z',
        'appeal_until' => '2026-12-10T18:00:00.000Z',
        'appeal_scope' => 'selected',
        'answered_by' => 'teacher',
    ])->assertSessionHasNoErrors();

    $settings = GradeSettings::current();
    expect($settings->options()['appeals'])->toBeTrue()
        ->and($settings->appeal_minutes)->toBe(2 * 1440 + 180)
        ->and($settings->appeal_from?->toIso8601String())->toBe('2026-12-01T08:00:00+00:00')
        ->and($settings->appeal_until?->toIso8601String())->toBe('2026-12-10T18:00:00+00:00')
        ->and($settings->appeal_scope)->toBe(AppealScope::Selected)
        ->and($settings->teacher_appeal_replies)->toBeTrue();

    $event = AuditEvent::query()->where('action', 'grades.appeal_settings_changed')->sole();
    expect($event->actor_id)->toBe($this->owner->id);

    // The administration answers; appeals off: the other settings stay.
    $this->actingAs($this->owner)->put('/admin/results/appeals', [
        'appeal_days' => 2,
        'appeal_hours' => 3,
        'appeal_scope' => 'all',
        'answered_by' => 'department',
    ])->assertSessionHasNoErrors();

    $settings = GradeSettings::current();
    expect($settings->options()['appeals'])->toBeFalse()
        ->and($settings->teacher_appeal_replies)->toBeFalse()
        ->and($settings->appeal_from)->toBeNull()
        ->and($settings->options()['total'])->toBeTrue();
});

it('refuses a closing date that is not after the opening date', function () {
    $this->actingAs($this->owner)->from('/admin?tab=appeals')->put('/admin/results/appeals', [
        'enabled' => '1',
        'appeal_from' => '2026-12-10T08:00:00.000Z',
        'appeal_until' => '2026-12-10T08:00:00.000Z',
        'appeal_scope' => 'all',
        'answered_by' => 'department',
    ])->assertSessionHasErrors(['appeal_until' => 'Appeals must close after they open.']);

    $this->actingAs($this->owner)->put('/admin/results/appeals', [
        'appeal_scope' => 'all',
        'answered_by' => 'nobody',
    ])->assertSessionHasErrors('answered_by');
});

it('keeps the appeal box when what the student sees is saved', function () {
    ($this->appealSettings)(true, 1440);

    $this->actingAs($this->owner)->put('/admin/results/student-view', ['options' => ['total']])->assertSessionHasNoErrors();

    $settings = GradeSettings::current();
    expect($settings->options()['appeals'])->toBeTrue()
        ->and($settings->options()['rank'])->toBeFalse()
        ->and($settings->appeal_minutes)->toBe(1440);
});

it('opens appeals at the opening date and counts the time from it', function () {
    $this->freezeSecond();
    $from = CarbonImmutable::now()->addDays(2);
    ($this->appealSettings)(true, 6 * 60, $from);
    ($this->publish)();

    expect(($this->openUntil)())->toBeNull()
        ->and(fn () => app(ManageAppeals::class)->submit($this->ali, $this->subject, Period::Month1, 'Question 3 was right.'))
        ->toThrow(DomainRuleViolation::class, 'Appeals are closed.');

    $this->travelTo($from->addMinute());
    expect(($this->openUntil)()?->getTimestamp())->toBe($from->addHours(6)->getTimestamp());
    app(ManageAppeals::class)->submit($this->ali, $this->subject, Period::Month1, 'Question 3 was right.');

    $this->travelTo($from->addHours(7));
    expect(($this->openUntil)())->toBeNull();
});

it('opens appeals at publication when the opening date has passed', function () {
    $this->freezeSecond();
    ($this->appealSettings)(true, 60, CarbonImmutable::now()->subDay());
    ($this->publish)();

    expect(($this->openUntil)()?->getTimestamp())->toBe(CarbonImmutable::now()->addHour()->getTimestamp());
});

it('closes at the date alone when no time is given', function () {
    $this->freezeSecond();
    $until = CarbonImmutable::now()->addDays(3);
    ($this->appealSettings)(true, null, CarbonImmutable::now()->addDay(), $until);
    ($this->publish)();

    expect(($this->openUntil)())->toBeNull();
    $this->travel(25)->hours();
    expect(($this->openUntil)()?->getTimestamp())->toBe($until->getTimestamp());
});

it('shows the appeals tab with the appeals counted by status and by who answered', function () {
    ($this->appealSettings)(true, 1440, null, null, true);
    ($this->publish)();
    $first = app(ManageAppeals::class)->submit($this->ali, $this->subject, Period::Month1, 'Question 3 was right.');
    app(ManageAppeals::class)->answerAsTeacher($this->teacher, $first, false, 'Checked twice.');
    $second = app(ManageAppeals::class)->submit($this->ali, $this->subject, Period::Month1, 'Question 4 too.');
    app(ManageAppeals::class)->decide($this->owner, $second, true, null);
    app(ManageAppeals::class)->submit($this->ali, $this->subject, Period::Month1, 'And question 5.');

    $this->actingAs($this->owner)->get('/admin?tab=appeals')->assertInertia(fn (Assert $page) => $page
        ->where('tab', 'appeals')
        ->where('appealCounts', ['pending' => 1, 'accepted' => 1, 'rejected' => 1, 'by_teacher' => 1, 'by_department' => 1])
        ->where('resultSettings.teacher_appeal_replies', true)
        ->where('resultSettings.appeal_from', null)
        ->has('access.appeals', 1)
        ->where('access.appeals.0.reason', 'And question 5.'));
});
