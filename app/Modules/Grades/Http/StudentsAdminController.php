<?php

namespace App\Modules\Grades\Http;

use App\Models\User;
use App\Modules\Grades\Application\Actions\ImportStudents;
use App\Modules\Grades\Application\Import\StudentImportPlan;
use App\Modules\Grades\Models\Student;
use App\Platform\Files\FileStore;
use App\Platform\Files\StoredFile;
use App\Platform\Tables\TableColumns;
use App\Platform\Tables\TableState;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Students (ADR-0034): the list, and the import in two steps. The file goes
 * through FileStore (type from its bytes, random stored name, audited
 * upload), its id is kept in the session, the preview reads it, and only
 * "apply" changes the students, planning again from the same file.
 */
class StudentsAdminController
{
    private const SESSION = 'grades.import';

    public function index(Request $request, StudentsTable $table, #[CurrentUser] User $viewer): Response
    {
        $state = TableState::from($table, $request->query());
        $students = $state->paginate($table, Student::query());

        return Inertia::render('modules/grades/pages/admin/students', [
            'students' => [
                'rows' => array_values($students->getCollection()->map(fn (Student $student): array => [
                    'id' => $student->id,
                    'name' => $student->full_name,
                    'number' => $student->student_number,
                    'stage' => $student->stage->value,
                    'section' => $student->section->value,
                ])->all()),
                'page' => $students->currentPage(),
                'last_page' => $students->lastPage(),
                'total' => $students->total(),
                'from' => $students->firstItem(),
                'to' => $students->lastItem(),
            ],
            'state' => $state->toArray(),
            'columns' => TableColumns::for($viewer->id, $table),
            'canImport' => $viewer->can('grades.manage'),
        ]);
    }

    public function upload(Request $request, FileStore $files, #[CurrentUser] User $user): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file']]);

        $types = array_intersect_key((array) config('platform.files.types'), array_flip(['xlsx', 'csv']));
        $stored = $files->store($request->file('file'), $types, $user, $user);
        $request->session()->put(self::SESSION, $stored->uuid);

        return to_route('grades.admin.students.preview');
    }

    public function preview(Request $request, ImportStudents $import, #[CurrentUser] User $user): Response|RedirectResponse
    {
        $file = $this->pending($request, $user);

        if ($file === null) {
            return to_route('grades.admin.students');
        }

        return Inertia::render('modules/grades/pages/admin/import-preview', [
            'file' => $file->original_name,
            ...$this->present($import->preview($user, $this->path($file), $file->extension)),
        ]);
    }

    public function apply(Request $request, ImportStudents $import, #[CurrentUser] User $user): RedirectResponse
    {
        $file = $this->pending($request, $user);

        if ($file === null) {
            return to_route('grades.admin.students');
        }

        $plan = $import->apply($user, $this->path($file), $file->extension, $file->original_name);
        $request->session()->forget(self::SESSION);

        $counts = $plan->counts();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Import done: :created added, :updated updated, :rejected not imported.', [
            'created' => $counts['created'], 'updated' => $counts['updated'], 'rejected' => $counts['rejected'],
        ])]);

        return to_route('grades.admin.students');
    }

    public function cancel(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION);

        return to_route('grades.admin.students');
    }

    /**
     * The file this person uploaded for the import in progress, if any.
     */
    private function pending(Request $request, User $user): ?StoredFile
    {
        $uuid = $request->session()->get(self::SESSION);

        return is_string($uuid)
            ? StoredFile::query()->where('uuid', $uuid)->where('uploaded_by', $user->id)->first()
            : null;
    }

    private function path(StoredFile $file): string
    {
        return Storage::disk($file->disk)->path($file->path);
    }

    /**
     * @return array{counts: array{created: int, updated: int, unchanged: int, rejected: int, not_in_file: int}, creates: list<array{row: int, name: string, stage: int, section: string, number: string|null}>, updates: list<array{row: int, name: string, changes: array<string, array{from: string|int|null, to: string|int|null}>}>, rejected: list<array{row: int, reason: string}>}
     */
    private function present(StudentImportPlan $plan): array
    {
        return [
            'counts' => $plan->counts(),
            'creates' => array_map(fn (array $row): array => [
                'row' => $row['row'], 'name' => $row['full_name'], 'stage' => $row['stage'], 'section' => $row['section'], 'number' => $row['student_number'],
            ], $plan->creates),
            'updates' => array_map(fn (array $row): array => [
                'row' => $row['row'], 'name' => $row['full_name'], 'changes' => $row['changes'],
            ], $plan->updates),
            'rejected' => $plan->rejected,
        ];
    }
}
