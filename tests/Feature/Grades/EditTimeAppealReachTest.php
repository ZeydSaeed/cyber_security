<?php

use App\Modules\Grades\Application\Actions\ChangeEntryWindow;
use App\Modules\Grades\Application\Actions\ManageAccess;
use App\Modules\Grades\Application\Actions\ManageAppeals;
use App\Modules\Grades\Application\Actions\ManageResults;
use App\Modules\Grades\Application\Actions\PublishResults;
use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Application\StudentCodes;
use App\Modules\Grades\Domain\AppealScope;
use App\Modules\Grades\Domain\EditPolicy;
use App\Modules\Grades\Domain\GradeBands;
use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\ResultDisplay;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\TeacherAccess;
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
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Grades\GradesFixtures;

/*
| The owner's changes of 2026-11-19 (ADR-0043): a mark's editing time in
| days, hours and minutes and/or until a date, used only by "enter and
| change" and "change for a limited time"; each mark's journey shown to
| its teacher; the student's absences and the appeal box on the card;
| appeals open for a time and/or until a date, to every student or only
| to chosen ones.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['teacher', 'student'] as $who) {
        RateLimiter::clear("grades-{$who}:minute:127.0.0.1");
        RateLimiter::clear("grades-{$who}:hour:127.0.0.1");
    }

    $this->owner = GradesFixtures::owner();
    $this->access = app(ManageAccess::class);
    $this->teacher = Teacher::factory()->withCode('river-stone-81')->create(['name' => 'Huda Karim']);
    $this->subject = Subject::factory()->stage(Stage::Second)->create(['name' => 'Networks', 'sort_order' => 1, 'teacher_id' => $this->teacher->id]);
    $this->other = Subject::factory()->stage(Stage::Second)->create(['name' => 'Crypto', 'sort_order' => 2, 'teacher_id' => $this->teacher->id]);
    $this->ali = Student::factory()->named('Ali Hassan')->in(Stage::Second, Section::A)->create();
    $codes = app(StudentCodes::class);
    $this->ali->forceFill(['code_digest' => $codes->digest('AAAA-BBBB'), 'code_encrypted' => $codes->encrypt('AAAA-BBBB')])->save();
    GradesFixtures::openWindow();
});

/**
 * @param  array<string, mixed>  $changes
 */
function limitSettings(object $test, array $changes): GradeSettings
{
    $settings = GradeSettings::current();

    return $test->access->settings($test->owner, [
        'teacher_attempts_per_minute' => $settings->teacher_attempts_per_minute,
        'teacher_attempts_per_hour' => $settings->teacher_attempts_per_hour,
        'student_attempts_per_minute' => $settings->student_attempts_per_minute,
        'student_attempts_per_hour' => $settings->student_attempts_per_hour,
        'teacher_max_logins' => $settings->teacher_max_logins,
        'student_max_logins' => $settings->student_max_logins,
        'teacher_mode' => $settings->teacher_mode,
        'teacher_edit_minutes' => $settings->teacher_edit_minutes,
        'teacher_edit_until' => $settings->teacher_edit_until,
        'student_access' => $settings->student_access,
        'remember_days' => $settings->remember_days,
        ...$changes,
    ]);
}

function saveAsTeacher(object $test, Mark $mark): array
{
    return app(SaveMarks::class)->handle($test->teacher->fresh(), $test->subject, Section::A, Period::Month1, [$test->ali->uuid => $mark]);
}

/**
 * @param  array<string, bool>  $options
 */
function cardSettings(object $test, array $options, ?int $appealMinutes = null, ?CarbonImmutable $until = null, AppealScope $scope = AppealScope::All): void
{
    app(ManageResults::class)->settings($test->owner, 50, null, null, GradeBands::DEFAULTS, [...ResultDisplay::OPTIONS, ...$options], ResultDisplay::TONED, $appealMinutes, null, $until, $scope);
}

function publishAll(object $test): void
{
    app(ChangeEntryWindow::class)->handle($test->owner, Period::Month1, WindowStatus::Closed);
    app(PublishResults::class)->handle($test->owner, Period::Month1, true);
}

describe('the editing time', function () {
    it('locks a mark after the minutes given', function () {
        limitSettings($this, ['teacher_mode' => TeacherAccess::Timed, 'teacher_edit_minutes' => 90]);
        saveAsTeacher($this, Mark::of(60));

        $this->travel(89)->minutes();
        expect(saveAsTeacher($this, Mark::of(61))['changed'])->toBe(1);

        $this->travel(2)->minutes();
        expect(fn () => saveAsTeacher($this, Mark::of(62)))->toThrow(DomainRuleViolation::class);
    });

    it('locks every mark at the date, whichever comes first', function () {
        limitSettings($this, [
            'teacher_mode' => TeacherAccess::Timed,
            'teacher_edit_minutes' => 7 * 1440,
            'teacher_edit_until' => CarbonImmutable::now()->addHours(2),
        ]);
        saveAsTeacher($this, Mark::of(60));

        $this->travel(3)->hours();
        expect(fn () => saveAsTeacher($this, Mark::of(61)))->toThrow(DomainRuleViolation::class);
    });

    it('applies a limit to "enter and change" too, and none when it is empty', function () {
        limitSettings($this, ['teacher_mode' => TeacherAccess::Edit, 'teacher_edit_minutes' => 30]);
        saveAsTeacher($this, Mark::of(60));
        $this->travel(31)->minutes();
        expect(fn () => saveAsTeacher($this, Mark::of(61)))->toThrow(DomainRuleViolation::class);

        limitSettings($this, ['teacher_mode' => TeacherAccess::Edit, 'teacher_edit_minutes' => null]);
        expect(saveAsTeacher($this, Mark::of(61))['changed'])->toBe(1);
    });

    it('keeps the saved limit when read-only is chosen, which uses none', function () {
        limitSettings($this, ['teacher_mode' => TeacherAccess::Timed, 'teacher_edit_minutes' => 120]);
        // Disabled fields are not sent: the page posts no limit.
        limitSettings($this, ['teacher_mode' => TeacherAccess::ViewOnly, 'teacher_edit_minutes' => null, 'teacher_edit_until' => null]);

        expect(GradeSettings::current()->teacher_edit_minutes)->toBe(120);
    });

    it('takes days, hours, minutes and a date from the page, and audits them', function () {
        $this->actingAs($this->owner)->put('/admin/access', [
            'teacher_attempts_per_minute' => 5,
            'teacher_attempts_per_hour' => 20,
            'student_attempts_per_minute' => 5,
            'student_attempts_per_hour' => 20,
            'teacher_mode' => 'timed',
            'teacher_edit_days' => 2,
            'teacher_edit_hours' => 3,
            'teacher_edit_minutes' => 15,
            'teacher_edit_until' => '2026-12-01T09:30:00.000Z',
            'student_access' => 'allowed',
            'remember_days' => 30,
        ])->assertSessionHasNoErrors();

        $settings = GradeSettings::current();
        $changes = AuditEvent::query()->where('action', 'grades.access_settings_changed')->sole()->changes;
        expect($settings->teacher_edit_minutes)->toBe(2 * 1440 + 3 * 60 + 15)
            ->and($settings->teacher_edit_until?->toIso8601String())->toBe('2026-12-01T09:30:00+00:00')
            ->and($changes['teacher_edit_minutes'])->toEqual(['from' => null, 'to' => 3075]);

        $this->actingAs($this->owner)->get('/admin')->assertInertia(fn (Assert $page) => $page
            ->where('access.settings.teacher_edit_minutes', 3075)
            ->where('access.settings.teacher_edit_until', '2026-12-01T09:30:00+00:00'));
    });

    it('uses a teacher\'s own limit as a whole, and tells the teacher', function () {
        limitSettings($this, ['teacher_mode' => TeacherAccess::Timed, 'teacher_edit_minutes' => 600]);
        $until = CarbonImmutable::now()->addDay()->startOfMinute();
        $this->access->teacher($this->owner, $this->teacher, null, null, null, $until);

        $this->post('/teacher', ['code' => 'river-stone-81']);
        $this->get("/teacher/subjects/{$this->subject->uuid}/A")->assertInertia(fn (Assert $page) => $page
            ->where('access.state', 'timed')
            ->where('access.minutes', null)
            ->where('access.deadline', $until->format(DATE_ATOM)));
    });

    it('refuses an editing time over a year', function () {
        expect(fn () => limitSettings($this, ['teacher_mode' => TeacherAccess::Timed, 'teacher_edit_minutes' => 525601]))
            ->toThrow(DomainRuleViolation::class, 'Give an editing time from 1 minute to 365 days.');
    });

    it('decides by the policy alone', function () {
        $now = CarbonImmutable::parse('2026-11-19 12:00');
        $policy = fn (TeacherAccess $mode, ?int $minutes, ?CarbonImmutable $deadline) => new EditPolicy(
            mode: $mode, editMinutes: $minutes, grantUntil: null, windowOpen: true, published: false, now: $now, editDeadline: $deadline,
        );

        expect($policy(TeacherAccess::Edit, null, null)->canChange($now->subYear()))->toBeTrue()
            ->and($policy(TeacherAccess::Edit, 60, null)->canChange($now->subMinutes(61)))->toBeFalse()
            ->and($policy(TeacherAccess::Timed, null, $now->subMinute())->canChange($now))->toBeFalse()
            ->and($policy(TeacherAccess::Timed, null, $now->subMinute())->canChange(null))->toBeTrue()
            ->and($policy(TeacherAccess::ViewOnly, 60, null)->isLimited())->toBeFalse()
            ->and($policy(TeacherAccess::Timed, 60, $now->addMinutes(30))->editableUntil($now))->toBe($now->addMinutes(30)->getTimestamp());
    });
});

describe('a mark\'s journey', function () {
    it('shows the teacher each change, who made it, and when, only once asked', function () {
        saveAsTeacher($this, Mark::of(40));
        app(SaveMarks::class)->handle($this->owner, $this->subject, Section::A, Period::Month1, [$this->ali->uuid => Mark::of(45)]);

        $this->post('/teacher', ['code' => 'river-stone-81']);
        $this->get("/teacher/subjects/{$this->subject->uuid}/A")->assertInertia(fn (Assert $page) => $page
            ->missing('history')
            ->reloadOnly('history', fn (Assert $reload) => $reload
                ->has('history', 2)
                ->where('history.0.from', 40)
                ->where('history.0.to', 45)
                ->where('history.0.owner', true)
                ->where('history.0.student_uuid', $this->ali->uuid)
                ->where('history.1.from', null)
                ->where('history.1.to', 40)
                ->where('history.1.by', 'Huda Karim')));
    });
});

describe('the student\'s card', function () {
    it('shows the absences only when the owner ticks them, and never the department\'s flag', function () {
        app(SaveMarks::class)->handle($this->owner, $this->other, Section::A, Period::Month1, [$this->ali->uuid => Mark::absent()]);
        app(ManageResults::class)->settings($this->owner, 50, null, null, GradeBands::DEFAULTS, ResultDisplay::OPTIONS, ResultDisplay::TONED, null, 1);
        publishAll($this);
        $this->post('/student', ['code' => 'AAAA-BBBB']);

        $this->get('/student/result')->assertInertia(fn (Assert $page) => $page
            ->where('card.student.absences', null)
            ->missing('card.student.frequent_absence'));

        cardSettings($this, ['absences' => true]);
        $this->get('/student/result')->assertInertia(fn (Assert $page) => $page->where('card.student.absences', 1));
    });

    it('closes appeals when the appeal box is not ticked', function () {
        saveAsTeacher($this, Mark::of(55));
        cardSettings($this, ['appeals' => false], 1440);
        publishAll($this);
        $this->post('/student', ['code' => 'AAAA-BBBB']);

        $this->get('/student/result')->assertInertia(fn (Assert $page) => $page->where('appealUntil', null));
    });

    it('closes appeals at the date when it comes before the time', function () {
        $this->freezeSecond();
        saveAsTeacher($this, Mark::of(55));
        cardSettings($this, [], 3 * 1440, CarbonImmutable::now()->addHours(5));
        publishAll($this);

        expect(ManageAppeals::openUntil($this->ali, GradeSettings::current(), GradePeriod::for(Period::Month1))?->getTimestamp())
            ->toBe(CarbonImmutable::now()->addHours(5)->getTimestamp());

        $this->travel(6)->hours();
        expect(fn () => app(ManageAppeals::class)->submit($this->ali, $this->subject, Period::Month1, 'My answer was right.'))
            ->toThrow(DomainRuleViolation::class, 'Appeals are closed.');
    });

    it('opens appeals only to the students the owner chooses', function () {
        saveAsTeacher($this, Mark::of(55));
        cardSettings($this, [], 1440, null, AppealScope::Selected);
        publishAll($this);
        $this->post('/student', ['code' => 'AAAA-BBBB']);

        $this->get('/student/result')->assertInertia(fn (Assert $page) => $page->where('appealUntil', null));
        $this->actingAs($this->owner)->get('/admin/students')->assertInertia(fn (Assert $page) => $page
            ->where('appealScope', 'selected')
            ->where('students.rows.0.appeal_allowed', false));

        $this->actingAs($this->owner)->put("/admin/access/students/{$this->ali->uuid}/appeal", ['allowed' => true])->assertSessionHasNoErrors();

        expect($this->ali->fresh()->appeal_allowed)->toBeTrue()
            ->and(AuditEvent::query()->where('action', 'grades.student_appeal_allowed')->sole()->actor_id)->toBe($this->owner->id);
        // A guard keeps its signed-in model between requests of one test;
        // a real request reads the student afresh.
        app('auth')->forgetGuards();
        $this->get('/student/result')->assertInertia(fn (Assert $page) => $page->whereNot('appealUntil', null));
    });

    it('takes the appeal time and date from the page', function () {
        // From the appeals tab of the settings (2026-11-20).
        $this->actingAs($this->owner)->put('/admin/results/student-view', [
            'options' => ['total', 'absences'],
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->put('/admin/results/appeals', [
            'enabled' => '1',
            'appeal_days' => 1,
            'appeal_hours' => 12,
            'appeal_until' => '2026-12-05T18:00:00.000Z',
            'appeal_scope' => 'selected',
            'answered_by' => 'department',
        ])->assertSessionHasNoErrors();

        $settings = GradeSettings::current();
        expect($settings->appeal_minutes)->toBe(36 * 60)
            ->and($settings->appeal_until?->toIso8601String())->toBe('2026-12-05T18:00:00+00:00')
            ->and($settings->appeal_scope)->toBe(AppealScope::Selected)
            ->and($settings->options()['absences'])->toBeTrue()
            ->and($settings->options()['rank'])->toBeFalse();
    });
});
