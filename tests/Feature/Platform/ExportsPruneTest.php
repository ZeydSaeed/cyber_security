<?php

use Illuminate\Console\Scheduling\Schedule;

/*
| Generated downloads that were never sent must not stay (review,
| 2026-11-18: ~130 workbooks with names and marks had piled up).
*/

it('deletes exports older than an hour and keeps newer ones', function () {
    $directory = storage_path('app/private/exports');
    is_dir($directory) || mkdir($directory, 0700, true);

    $old = $directory.'/results-prune-test-old.xlsx';
    $new = $directory.'/results-prune-test-new.xlsx';
    file_put_contents($old, 'x');
    file_put_contents($new, 'x');
    touch($old, time() - 2 * 3600);

    $this->artisan('platform:exports-prune')->assertSuccessful();

    expect(file_exists($old))->toBeFalse()
        ->and(file_exists($new))->toBeTrue();

    unlink($new);
});

it('runs every hour', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'platform:exports-prune'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 * * * *');
});
