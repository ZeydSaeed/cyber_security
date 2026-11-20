<?php

use App\Access\Role;
use App\Models\User;
use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Database\Factories\SubjectFactory;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\GradeMark;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Platform\Access\RoleAssignment;
use App\Platform\Audit\AuditEvent;
use App\Platform\Audit\VerifyAuditChain;
use App\Platform\Exceptions\DomainRuleViolation;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxMessage;
use App\Platform\Preferences\UserPreference;
use App\Platform\Preferences\WorkspacePreferences;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Tests\Support\Outbox\CountingOutboxJob;
use Tests\Support\Parallel;

/*
| Real races: separate PHP processes, one PostgreSQL database, a shared start
| time. Committed data (DatabaseMigrations, see tests/Pest.php), so every
| process sees it. PostgreSQL only: `composer test:pgsql`.
*/

beforeEach(function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Concurrency tests need PostgreSQL (composer test:pgsql).');
    }

    // Added to the application's handlers (modules register their own).
    config(['platform.outbox.handlers' => [...(array) config('platform.outbox.handlers'), 'test.counted' => CountingOutboxJob::class]]);
});

function newAdministrator(): User
{
    $user = User::factory()->withTwoFactor()->create();
    RoleAssignment::query()->forceCreate(['user_id' => $user->id, 'role' => Role::Administrator->value]);

    return $user;
}

it('lets exactly one of two administrators remove the other when both try at once', function () {
    foreach (range(1, 5) as $round) {
        $first = newAdministrator();
        $second = newAdministrator();

        $results = Parallel::run('revoke-administrator', [[$first->id, $second->id], [$second->id, $first->id]]);
        $succeeded = array_values(array_filter($results, fn (array $result): bool => $result['ok']));
        $refused = array_values(array_filter($results, fn (array $result): bool => ! $result['ok']));

        $stillAdministrators = RoleAssignment::query()
            ->whereIn('user_id', [$first->id, $second->id])
            ->where('role', Role::Administrator->value)
            ->count();

        expect($succeeded)->toHaveCount(1, "round {$round}")
            ->and($stillAdministrators)->toBe(1, "round {$round}")
            ->and($refused[0]['error'])->toBeIn([AuthorizationException::class, DomainRuleViolation::class]);
    }

    expect(app(VerifyAuditChain::class)->run()['broken_at'])->toBeNull();
});

it('grants a role once when six requests race', function () {
    $admin = newAdministrator();
    $target = User::factory()->create();

    $results = Parallel::run('assign-auditor', array_fill(0, 6, [$admin->id, $target->id]));

    expect(array_column($results, 'ok'))->each->toBeTrue()
        ->and(RoleAssignment::query()->where('user_id', $target->id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'access.role_assigned')->count())->toBe(1)
        ->and(app(VerifyAuditChain::class)->run()['broken_at'])->toBeNull();
});

it('relays each outbox message once with four relays, and applies each once despite duplicates', function () {
    DB::transaction(function (): void {
        foreach (range(1, 300) as $n) {
            app(Outbox::class)->record('test.counted', ['n' => $n]);
        }
    });

    $relays = Parallel::run('relay-until-empty', array_fill(0, 4, [25]));

    expect(array_sum(array_column($relays, 'dispatched')))->toBe(300)
        ->and(OutboxMessage::query()->where('status', 'dispatched')->count())->toBe(300)
        ->and(DB::table('jobs')->where('queue', 'outbox')->count())->toBe(300);

    // Simulate at-least-once delivery: 50 messages delivered a second time.
    $bus = app(Dispatcher::class);
    OutboxMessage::query()->orderBy('id')->limit(50)->get()->each(
        fn (OutboxMessage $message) => $bus->dispatch((new CountingOutboxJob($message->uuid, $message->payload))->onConnection('database')),
    );

    Parallel::run('work-outbox-queue', array_fill(0, 3, []));

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('cache')->where('key', 'like', 'outbox-effect:%')->count())->toBe(300)
        ->and(DB::table('processed_messages')->count())->toBe(300);
});

it('keeps both of two workspace choices saved at the same moment', function () {
    foreach (range(1, 5) as $round) {
        $user = User::factory()->create();

        // The interface saves each choice with its own request (ADR-0028).
        $results = Parallel::run('save-preference', [
            [$user->id, 'density', '"compact"'],
            [$user->id, 'shortcuts', 'false'],
        ]);

        expect(array_column($results, 'ok'))->toBe([true, true], "round {$round}")
            ->and(UserPreference::read($user->id, WorkspacePreferences::KEY))
            ->toEqual(['density' => 'compact', 'shortcuts' => false], "round {$round}");
    }
});

/**
 * A subject with five students in section A and an open first month.
 *
 * @return array{0: Subject, 1: list<string>}
 */
function sectionWithOpenWindow(): array
{
    $subject = Subject::factory()->create();
    $students = Student::factory()->count(5)->create()->pluck('uuid')->all();
    GradePeriod::for(Period::Month1)->forceFill(['status' => WindowStatus::Open, 'opened_at' => now(), 'closed_at' => null])->save();

    return [$subject, $students];
}

it('saves a section once when a teacher sends the same marks four times at once', function () {
    [$subject, $students] = sectionWithOpenWindow();
    $marks = (string) json_encode(array_fill_keys($students, '75'));

    // Four, not six: the owner role's CONNECTION LIMIT 10 (see below).
    $results = Parallel::run('save-marks', array_fill(0, 4, [SubjectFactory::teacherOf($subject)?->id, $subject->id, 'A', $marks]));

    expect(array_values(array_filter($results, fn (array $result): bool => ! $result['ok'])))->toBe([])
        ->and(array_sum(array_column($results, 'changed')))->toBe(5)
        ->and(GradeMark::query()->where('subject_id', $subject->id)->count())->toBe(5)
        ->and(AuditEvent::query()->where('action', 'grades.marks_saved')->count())->toBe(1)
        ->and(OutboxMessage::query()->where('topic', SaveMarks::TOPIC)->count())->toBe(1)
        ->and(app(VerifyAuditChain::class)->run()['broken_at'])->toBeNull();
});

it('keeps both of two saves of different students at the same moment', function () {
    foreach (range(1, 3) as $round) {
        [$subject, $students] = sectionWithOpenWindow();

        $results = Parallel::run('save-marks', [
            [SubjectFactory::teacherOf($subject)?->id, $subject->id, 'A', (string) json_encode([$students[0] => '60', $students[1] => '61'])],
            [SubjectFactory::teacherOf($subject)?->id, $subject->id, 'A', (string) json_encode([$students[2] => '62', $students[3] => 'absent'])],
        ]);

        expect(array_column($results, 'ok'))->toBe([true, true], "round {$round}")
            ->and(GradeMark::query()->where('subject_id', $subject->id)->count())->toBe(4, "round {$round}");
    }
});

it('either saves before the window closes or refuses, never after', function () {
    $admin = newAdministrator();

    foreach (range(1, 5) as $round) {
        [$subject, $students] = sectionWithOpenWindow();

        $results = Parallel::run('save-or-close', [
            ['save', SubjectFactory::teacherOf($subject)?->id, $subject->id, 'A', (string) json_encode(array_fill_keys($students, '90'))],
            ['close', $admin->id],
        ]);
        $save = $results[0];
        $marks = GradeMark::query()->where('subject_id', $subject->id)->count();
        $period = GradePeriod::for(Period::Month1);

        expect($results[1]['ok'])->toBeTrue("round {$round}")
            ->and($period->status)->toBe(WindowStatus::Closed);

        if ($save['ok']) {
            // Committed while open: every mark, written no later than the close.
            $savedAt = (string) GradeMark::query()->where('subject_id', $subject->id)->max('updated_at');
            expect($marks)->toBe(5, "round {$round}")
                ->and(strtotime($savedAt))->toBeLessThanOrEqual($period->closed_at?->getTimestamp() ?? 0);
        } else {
            expect($save['message'])->toBe('Mark entry is closed for this period.')
                ->and($marks)->toBe(0, "round {$round}");
        }
    }
});

it('adds a student once when two owners add the same student at once', function () {
    $admin = newAdministrator();

    $results = Parallel::run('add-student', [[$admin->id, 'Ali Hassan Jaber'], [$admin->id, 'Ali Hassan Jaber']]);

    // One is added; the other is refused, by the check or by the database.
    expect(array_column($results, 'ok'))->toEqualCanonicalizing([true, false])
        ->and(Student::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'grades.student_created')->count())->toBe(1);
});
