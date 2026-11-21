<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Backup\DatabaseExport;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * The database copy (owner's request, 2026-11-21): "take a copy" makes a
 * plain SQL file on the server; "save" sends it to the browser, which asks
 * where to put it. A copy can be saved once, by the person who took it in
 * the same session. Both steps are audited.
 *
 * Each copy asks for the person's own password again (owner's request,
 * 2026-11-21): the file holds every table unencrypted, so an open session
 * alone is not enough. A wrong password is audited too; the route's
 * throttle limits the guesses.
 */
class BackupController extends Controller
{
    private const SESSION_KEY = 'backup.database_export';

    public function index(): Response
    {
        return Inertia::render('admin/backup');
    }

    public function store(Request $request, DatabaseExport $export, AuditLogger $audit, #[CurrentUser] User $user): JsonResponse
    {
        try {
            $request->validate(['password' => ['required', 'string', 'current_password:web']]);
        } catch (ValidationException $exception) {
            if ($request->filled('password')) {
                $audit->record('backup.password_refused', actor: $user);
            }

            throw $exception;
        }

        try {
            $copy = $export->prepare();
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => __('The copy could not be taken.')], 500);
        }

        $request->session()->put(self::SESSION_KEY, $copy);
        $audit->record('backup.database_exported', metadata: ['bytes' => $copy['bytes']], actor: $user);

        return response()->json([
            'name' => $copy['name'],
            'bytes' => $copy['bytes'],
            'url' => route('admin.backup.download', $copy['id']),
        ])->header('Cache-Control', 'no-store');
    }

    public function download(Request $request, string $copy, DatabaseExport $export, AuditLogger $audit, #[CurrentUser] User $user): BinaryFileResponse
    {
        $prepared = (array) $request->session()->get(self::SESSION_KEY);
        abort_unless(($prepared['id'] ?? null) === $copy, 404);

        $path = $export->find($copy);
        abort_if($path === null, 404);

        $request->session()->forget(self::SESSION_KEY);
        $audit->record('backup.database_downloaded', metadata: ['bytes' => $prepared['bytes'] ?? null], actor: $user);

        return response()
            ->download($path, (string) $prepared['name'], [
                'Content-Type' => 'application/sql',
                'Cache-Control' => 'no-store',
            ])
            ->deleteFileAfterSend();
    }
}
