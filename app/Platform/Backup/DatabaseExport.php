<?php

namespace App\Platform\Backup;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A plain copy of the database for the owner to save on their own computer
 * (owner's request, 2026-11-21): pg_dump as the read-only backup role, in
 * plain SQL, not encrypted, so it opens in any editor and is restored with
 * `psql -f`. It is not a backup set of ADR-0022 (no files, no secrets, no
 * keys); anyone holding the file reads every table.
 *
 * The file waits on the server only between "take a copy" and "save": it
 * is deleted once sent, and any copy older than an hour is deleted on the
 * next export.
 */
class DatabaseExport
{
    private const LIFETIME_SECONDS = 3600;

    /**
     * @return array{id: string, name: string, bytes: int}
     */
    public function prepare(): array
    {
        $username = (string) config('platform.backup.database.username');

        if ($username === '') {
            throw new RuntimeException('The backup role is not configured (BACKUP_DB_USERNAME).');
        }

        $this->prune();
        File::ensureDirectoryExists($this->directory(), 0700);

        $id = (string) Str::uuid();
        $path = $this->path($id);
        $connection = (array) config('database.connections.'.config('database.default'));

        $dump = new Process([
            BackupSettings::tool('pg_dump'), '--format=plain', '--encoding=UTF8',
            '--no-owner', '--no-privileges', ...BackupSettings::dumpExclusions(),
            '--host='.$connection['host'], '--port='.$connection['port'],
            '--username='.$username, '--dbname='.$connection['database'],
            '--file='.$path,
        ], null, ['PGPASSWORD' => (string) config('platform.backup.database.password')]);
        $dump->setTimeout(600);
        $dump->run();

        // pg_dump writes this line last: a file without it is cut short.
        if (! $dump->isSuccessful() || ! is_file($path) || ! str_contains($this->tail($path), 'PostgreSQL database dump complete')) {
            File::delete($path);

            throw new RuntimeException('pg_dump failed: '.trim($dump->getErrorOutput()));
        }

        return [
            'id' => $id,
            'name' => $connection['database'].'-'.now()->format('Y-m-d-His').'.sql',
            'bytes' => (int) filesize($path),
        ];
    }

    /**
     * The prepared file, or null when it is gone or the id is not one.
     */
    public function find(string $id): ?string
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        $path = $this->path($id);

        return is_file($path) ? $path : null;
    }

    private function prune(): void
    {
        foreach (File::glob($this->directory().'/*.sql') as $file) {
            if (filemtime($file) < time() - self::LIFETIME_SECONDS) {
                File::delete($file);
            }
        }
    }

    private function tail(string $path): string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        fseek($handle, -min(512, (int) filesize($path)), SEEK_END);
        $tail = (string) fread($handle, 512);
        fclose($handle);

        return $tail;
    }

    private function directory(): string
    {
        return BackupSettings::directory().'/exports';
    }

    private function path(string $id): string
    {
        return $this->directory()."/{$id}.sql";
    }
}
