<?php

use App\Modules\Grades\Application\Actions\ChangeEntryWindow;
use App\Modules\Grades\Application\Actions\ManageAccess;
use App\Modules\Grades\Application\Actions\ManageAppeals;
use App\Modules\Grades\Application\Actions\ManageResults;
use App\Modules\Grades\Application\Actions\PublishResults;
use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Application\Jobs\NotifyGradesManagers;
use App\Modules\Grades\Application\Notifications\GradesNotification;
use App\Modules\Grades\Application\StudentCodes;
use App\Modules\Grades\Domain\AppealScope;
use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\ResultDisplay;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\TeacherAccess;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\GradeSettings;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditEvent;
use App\Platform\Audit\AuditLogger;
use App\Platform\Outbox\OutboxMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Grades\GradesFixtures;

/*
| The owner's further suggestions, approved 2026-11-19 (ADR-0045):
| reminders about appeals closing or waiting too long, the teacher's to-do
| at a glance, and who saw a student's result.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['teacher', 'student'] as $who) {
        RateLimiter::clear("grades-{$who}:minute:127.0.0.1");
    }

    $this->owner = GradesFixtures::owner();
    $this->teacher = Teacher::factory()->withCode('river-stone-81')->create(['name' => 'Huda Karim']);
    $this->networks = Subject::factory()->stage(Stage::Second)->create(['name' => 'Networks', 'teacher_id' => $this->teacher->id]);
    $this->ali = Student::factory()->named('Ali Hassan')->in(Stage::Second, Section::A)->create();
    $this->sara = Student::factory()->named('Sara Adel')->in(Stage::Second, Section::A)->create();
    $codes = app(StudentCodes::class);
    $this->ali->forceFill(['code_digest' => $codes->digest('AAAA-BBBB'), 'code_encrypted' => $codes->encrypt('AAAA-BBBB')])->save();
    GradesFixtures::openWindow();
});

function appealsFor(object $test, ?int $minutes): void
{
    app(ManageResults::class)->studentView($test->owner, ResultDisplay::OPTIONS, $minutes, null, AppealScope::All, false);
}

function publishSecondStage(object $test): void
{
    app(SaveMarks::class)->handle($test->teacher, $test->networks, Section::A, Period::Month1, [$test->ali->uuid => Mark::of(55)]);
    app(ChangeEntryWindow::class)->handle($test->owner, Period::Month1, WindowStatus::Closed);
    app(PublishResults::class)->handle($test->owner, Period::Month1, true);
}

/**
 * @return list<array<string, mixed>>
 */
function reminders(string $kind): array
{
    return OutboxMessage::query()->where('topic', NotifyGradesManagers::TOPIC)->get()
        ->filter(fn (OutboxMessage $message): bool => ($message->payload['kind'] ?? null) === $kind)
        ->map(fn (OutboxMessage $message): array => $message->payload['facts'])
        ->values()->all();
}

describe('appeal reminders', function () {
    it('tells the department a day before a section\'s appeals close, once', function () {
        appealsFor($this, 20 * 60);
        publishSecondStage($this);
        app(ManageAppeals::class)->submit($this->ali, $this->networks, Period::Month1, 'Question 3 was right.');

        Artisan::call('grades:appeal-reminders');
        Artisan::call('grades:appeal-reminders');

        $closing = reminders(GradesNotification::APPEALS_CLOSING);
        expect($closing)->toHaveCount(1)
            ->and($closing[0]['stage'])->toBe('2')
            ->and($closing[0]['section'])->toBe('A')
            ->and($closing[0]['count'])->toBe('1');
    });

    it('waits while the closing is more than a day away, and says nothing once closed', function () {
        appealsFor($this, 3 * 1440);
        publishSecondStage($this);

        Artisan::call('grades:appeal-reminders');
        expect(reminders(GradesNotification::APPEALS_CLOSING))->toBe([]);

        $this->travel(4)->days();
        Artisan::call('grades:appeal-reminders');
        expect(reminders(GradesNotification::APPEALS_CLOSING))->toBe([]);
    });

    it('tells the department once a day of appeals waiting two days or more', function () {
        appealsFor($this, 10 * 1440);
        publishSecondStage($this);
        app(ManageAppeals::class)->submit($this->ali, $this->networks, Period::Month1, 'Question 3 was right.');

        Artisan::call('grades:appeal-reminders');
        expect(reminders(GradesNotification::APPEALS_OVERDUE))->toBe([]);

        $this->travel(49)->hours();
        Artisan::call('grades:appeal-reminders');
        Artisan::call('grades:appeal-reminders');
        expect(reminders(GradesNotification::APPEALS_OVERDUE))->toEqual([['count' => '1']]);

        $this->travel(1)->days();
        Artisan::call('grades:appeal-reminders');
        expect(reminders(GradesNotification::APPEALS_OVERDUE))->toHaveCount(2);
    });

    it('delivers the reminder to those who manage the grades', function () {
        appealsFor($this, 20 * 60);
        publishSecondStage($this);
        Artisan::call('grades:appeal-reminders');
        Artisan::call('platform:outbox-relay');

        expect($this->owner->notifications()->get()->pluck('data.kind')->all())->toContain(GradesNotification::APPEALS_CLOSING);
    });

    it('is scheduled every hour', function () {
        Artisan::call('schedule:list');

        expect(Artisan::output())->toContain('grades:appeal-reminders');
    });
});

describe('the teacher\'s to-do', function () {
    it('counts the marks locking within a day and the students without a mark', function () {
        $settings = GradeSettings::current();
        app(ManageAccess::class)->settings($this->owner, [
            'teacher_attempts_per_minute' => $settings->teacher_attempts_per_minute,
            'teacher_attempts_per_hour' => $settings->teacher_attempts_per_hour,
            'student_attempts_per_minute' => $settings->student_attempts_per_minute,
            'student_attempts_per_hour' => $settings->student_attempts_per_hour,
            'teacher_max_logins' => null,
            'student_max_logins' => null,
            'teacher_mode' => TeacherAccess::Timed,
            'teacher_edit_minutes' => 3 * 1440,
            'teacher_edit_until' => null,
            'student_access' => $settings->student_access,
            'remember_days' => $settings->remember_days,
        ]);
        app(SaveMarks::class)->handle($this->teacher, $this->networks, Section::A, Period::Month1, [$this->ali->uuid => Mark::of(55)]);

        $this->post('/teacher', ['code' => 'river-stone-81']);
        $this->get('/teacher/subjects')->assertInertia(fn (Assert $page) => $page
            ->where('subjects.0.sections.0.locking_soon', 0)
            ->where('subjects.0.sections.0.entered', 1)
            ->where('subjects.0.sections.0.students', 2));

        $this->travel(2 * 1440 + 60)->minutes();
        $this->post('/teacher', ['code' => 'river-stone-81']);
        $this->get('/teacher/subjects')->assertInertia(fn (Assert $page) => $page->where('subjects.0.sections.0.locking_soon', 1));
    });
});

describe('who saw the result', function () {
    it('records the student\'s view once per visit, and lists every view on the owner\'s card', function () {
        appealsFor($this, null);
        publishSecondStage($this);

        $this->post('/student', ['code' => 'AAAA-BBBB']);
        $this->get('/student/result')->assertOk();
        $this->get('/student/result')->assertOk();
        expect(AuditEvent::query()->where('action', 'grades.student_result_viewed')->count())->toBe(1);

        // A teacher reads the section's results; they include Ali.
        app(AuditLogger::class)->record('grades.teacher_results_viewed', $this->teacher, ['stage' => 2, 'section' => 'A'], withoutActor: true);
        // Another section's view is not Ali's.
        app(AuditLogger::class)->record('grades.teacher_results_viewed', $this->teacher, ['stage' => 2, 'section' => 'B'], withoutActor: true);

        $this->actingAs($this->owner)->get("/admin/results/students/{$this->ali->uuid}")->assertInertia(fn (Assert $page) => $page
            ->has('views', 3)
            ->where('views', fn ($views) => collect($views)->pluck('kind')->sort()->values()->all() === ['student', 'teacher', 'user']
                && collect($views)->firstWhere('kind', 'teacher')['who'] === 'Huda Karim'
                && collect($views)->firstWhere('kind', 'student')['who'] === 'Ali Hassan'));

        expect(AuditEvent::query()->where('action', 'grades.student_card_viewed')->sole()->actor_id)->toBe($this->owner->id);
    });

    it('records nothing before the results are published', function () {
        $this->post('/student', ['code' => 'AAAA-BBBB']);
        $this->get('/student/result')->assertOk();

        expect(AuditEvent::query()->where('action', 'grades.student_result_viewed')->count())->toBe(0);
    });
});
