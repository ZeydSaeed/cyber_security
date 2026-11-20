<?php

/*
| One concurrent worker for tests/Concurrency (started by Tests\Support\Parallel).
| Boots the application, waits for the shared start time so all workers act
| at once, runs one scenario and prints its result as JSON. Refuses to run
| anywhere but the PostgreSQL test database.
|
|   php tests/Concurrency/worker.php <scenario> <start-at unix time> [args...]
*/

use App\Access\Role;
use App\Models\User;
use App\Modules\Grades\Application\Actions\ChangeEntryWindow;
use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Application\Actions\SaveStudent;
use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Access\AssignRole;
use App\Platform\Access\RevokeRole;
use App\Platform\Outbox\OutboxRelay;
use App\Platform\Preferences\WorkspacePreferences;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Outbox\CountingOutboxJob;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) config('database.connections.'.config('database.default').'.database');

if (! $app->environment('testing') || config('database.default') !== 'pgsql' || $database !== config('platform.database.testing_name')) {
    fwrite(STDERR, "Refusing: concurrency workers run only on the PostgreSQL test database (got {$database}).\n");
    exit(2);
}

// Added to the application's handlers (modules register their own).
config(['platform.outbox.handlers' => [...(array) config('platform.outbox.handlers'), 'test.counted' => CountingOutboxJob::class]]);

$scenario = $argv[1];
$startAt = (float) $argv[2];
$args = array_slice($argv, 3);

while (microtime(true) < $startAt) {
    usleep(200);
}

try {
    $result = match ($scenario) {
        'revoke-administrator' => (function () use ($app, $args): array {
            $app->make(RevokeRole::class)->handle(User::findOrFail((int) $args[0]), User::findOrFail((int) $args[1]), Role::Administrator);

            return ['ok' => true];
        })(),
        // args: user id, preference key, JSON value
        'save-preference' => (function () use ($args): array {
            WorkspacePreferences::save(Request::create('/'), (int) $args[0], [$args[1] => json_decode($args[2], true)]);

            return ['ok' => true];
        })(),
        'assign-auditor' => (function () use ($app, $args): array {
            $app->make(AssignRole::class)->handle(User::findOrFail((int) $args[0]), User::findOrFail((int) $args[1]), Role::Auditor);

            return ['ok' => true];
        })(),
        'relay-until-empty' => (function () use ($app, $args): array {
            $relay = $app->make(OutboxRelay::class);
            $dispatched = 0;

            do {
                $round = $relay->relay((int) $args[0]);
                $dispatched += $round['dispatched'];
            } while ($round['dispatched'] > 0);

            return ['ok' => true, 'dispatched' => $dispatched];
        })(),
        'work-outbox-queue' => (function (): array {
            Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'outbox', '--stop-when-empty' => true]);

            return ['ok' => true];
        })(),
        // args: teacher id, subject id, section, JSON {student uuid: mark}
        'save-marks' => saveMarks($app, $args),
        // args: save, teacher id, subject id, section, JSON marks | close, user id
        'save-or-close' => $args[0] === 'save'
            ? saveMarks($app, array_slice($args, 1))
            : (function () use ($app, $args): array {
                $app->make(ChangeEntryWindow::class)->handle(User::findOrFail((int) $args[1]), Period::Month1, WindowStatus::Closed);

                return ['ok' => true];
            })(),
        // args: user id, student name (first stage)
        'add-student' => (function () use ($app, $args): array {
            $app->make(SaveStudent::class)->create(User::findOrFail((int) $args[0]), (string) $args[1], Stage::First, null);

            return ['ok' => true];
        })(),
        default => throw new InvalidArgumentException("Unknown scenario {$scenario}"),
    };
} catch (Throwable $exception) {
    $result = ['ok' => false, 'error' => $exception::class, 'message' => $exception->getMessage()];
}

echo json_encode($result), PHP_EOL;

/**
 * @param  list<string>  $args  teacher id, subject id, section, JSON {student uuid: mark}
 * @return array{ok: true, changed: int}
 */
function saveMarks(Application $app, array $args): array
{
    $marks = array_map(fn (string $mark): ?Mark => Mark::fromInput($mark), (array) json_decode($args[3], true));
    $result = $app->make(SaveMarks::class)->handle(
        Teacher::query()->findOrFail((int) $args[0]),
        Subject::query()->findOrFail((int) $args[1]),
        Section::from($args[2]),
        Period::Month1,
        $marks,
    );

    return ['ok' => true, 'changed' => $result['changed']];
}
