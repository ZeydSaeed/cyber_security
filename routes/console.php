<?php

use App\Platform\Jobs\QueueSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Encrypted backup set every night (ADR-0022); restore drills are run by an
// operator on a separate machine with the private keys.
Schedule::command('platform:backup', ['daily'])->dailyAt('02:00')->withoutOverlapping()->onOneServer();

// A broken chain means the audit trail was altered (ADR-0013).
Schedule::command('platform:audit-verify')->dailyAt('02:30')->withoutOverlapping()->onOneServer();

// Fallback relay; production also runs `platform:outbox-relay --loop` as a
// service for sub-second delivery (ADR-0019).
Schedule::command('platform:outbox-relay')->everyMinute()->withoutOverlapping()->onOneServer();

// Backlog warnings per queue (QueueBusy, logged by the platform).
Schedule::command('queue:monitor', [
    implode(',', array_map(fn (QueueSettings $queue): string => $queue->connection().':'.$queue->name, QueueSettings::all())),
    '--max' => 100,
])->everyMinute()->onOneServer();

Schedule::command('queue:prune-failed', ['--hours' => 720])->daily()->onOneServer();

// Stale pending scans, records without bytes, bytes without records (ADR-0020).
Schedule::command('platform:files-sweep')->hourly()->withoutOverlapping()->onOneServer();

// Generated downloads (results workbooks, import templates) are deleted
// after they are sent; one never sent (a dropped connection) stayed for
// ever, with students' names and marks (review, 2026-11-18). Each lives
// for an hour at most.
Artisan::command('platform:exports-prune {--minutes=60}', function (): int {
    $directory = storage_path('app/private/exports');
    $cutoff = time() - 60 * max(1, (int) $this->option('minutes'));
    $removed = 0;

    foreach (is_dir($directory) ? (glob($directory.'/*.xlsx') ?: []) : [] as $file) {
        if (is_file($file) && filemtime($file) < $cutoff && unlink($file)) {
            $removed++;
        }
    }

    $this->info("Removed {$removed} old export(s).");

    return 0;
})->purpose('Delete generated downloads older than an hour');

Schedule::command('platform:exports-prune')->hourly()->withoutOverlapping()->onOneServer();
