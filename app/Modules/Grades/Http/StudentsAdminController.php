<?php

namespace App\Modules\Grades\Http;

use App\Models\User;
use App\Modules\Grades\Application\Actions\ImportStudents;
use App\Modules\Grades\Application\Actions\IssueStudentCodes;
use App\Modules\Grades\Application\Actions\SaveStudent;
use App\Modules\Grades\Application\Import\StudentImportPlan;
use App\Modules\Grades\Application\Import\StudentImportTemplate;
use App\Modules\Grades\Application\StudentCodes;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Models\Student;
use App\Platform\Audit\AuditLogger;
use App\Platform\Files\FileStore;
use App\Platform\Files\StoredFile;
use App\Platform\Tables\TableColumns;
use App\Platform\Tables\TableState;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Students (ADR-0034): the list, one student added, changed or deleted by
 * hand (SaveStudent), and the import in two steps, with a file to fill. The file goes
 * through FileStore (type from its bytes, random stored name, audited
 * upload), its id is kept in the session, the preview reads it, and only
 * "apply" changes the students, planning again from the same file.
 */
class StudentsAdminController
{
    private const SESSION = 'grades.import';

    /**
     * The list shows each student's code to those who manage the grades
     * (owner's request, 2026-11-16); each such showing is audited.
     */
    public function index(Request $request, StudentsTable $table, StudentCodes $codes, AuditLogger $audit, #[CurrentUser] User $viewer): Response
    {
        $state = TableState::from($table, $request->query());
        $students = $state->paginate($table, Student::query());
        $showCodes = $viewer->can('grades.manage');

        if ($showCodes && $students->count() > 0) {
            $audit->record('grades.student_codes_listed', null, ['page' => $students->currentPage(), 'count' => $students->count()], actor: $viewer);
        }

        return Inertia::render('modules/grades/pages/admin/students', [
            'students' => [
                'rows' => array_values($students->getCollection()->map(fn (Student $student): array => [
                    'id' => $student->id,
                    'uuid' => $student->uuid,
                    'has_code' => $student->code_digest !== null,
                    'code' => $showCodes && $student->code_encrypted !== null ? $codes->decrypt($student->code_encrypted) : null,
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
            'canImport' => $showCodes,
            'withoutCode' => Student::query()->whereNull('code_digest')->count(),
            'places' => ResultsController::places(),
        ]);
    }

    public function store(Request $request, SaveStudent $save, #[CurrentUser] User $user): RedirectResponse
    {
        $save->create($user, ...$this->studentFields($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Student added, with their code.')]);

        return back();
    }

    public function update(Request $request, Student $student, SaveStudent $save, #[CurrentUser] User $user): RedirectResponse
    {
        $request->validate(['code' => ['nullable', 'string', 'max:40']]);
        [$name, $stage, $section] = $this->studentFields($request);
        $save->update($user, $student, $name, $stage, $section, $request->filled('code') ? (string) $request->string('code') : null);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Changes saved.')]);

        return back();
    }

    public function destroy(Student $student, SaveStudent $save, #[CurrentUser] User $user): RedirectResponse
    {
        $save->delete($user, $student);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Student deleted.')]);

        return back();
    }

    /**
     * The file to fill for the import: headers on the first sheet, how to
     * fill each column and an example on the second.
     */
    public function template(StudentImportTemplate $template): BinaryFileResponse
    {
        $path = storage_path('app/private/exports/students-template-'.bin2hex(random_bytes(8)).'.xlsx');
        @mkdir(dirname($path), 0700, true);
        $arabic = App::getLocale() === 'ar';

        $template->write($path, [
            'sheet' => __('Students'),
            'help' => __('How to fill it'),
            'name' => __('Name'),
            'stage' => __('Stage'),
            'section' => __('Section'),
            'number' => __('Student number'),
            'column' => __('Column'),
            'how' => __('What to write'),
            'rules' => [
                'name' => __('The full name of the student, at least 3 letters.'),
                'stage' => __('1 for the first stage, 2 for the second.'),
                'section' => __('A or B for the second stage; leave it empty for the first stage.'),
                'number' => __('Optional: the number of the student at the school. If you write it, it must not repeat.'),
            ],
            'example' => __('Example'),
            'examples' => $arabic ? [
                ['أحمد علي حسن', '1', '', ''],
                ['سارة محمد كريم', '2', 'A', '2024017'],
                ['علي حسين جاسم', '2', 'B', ''],
            ] : [
                ['Ahmed Ali Hassan', '1', '', ''],
                ['Sara Mohammed Karim', '2', 'A', '2024017'],
                ['Ali Hussein Jasim', '2', 'B', ''],
            ],
            'notes' => [
                __('Write the students on the first sheet, one per row, under its headers.'),
                __('Keep the headers. The columns may be in any order.'),
                __('Save the file as Excel (.xlsx) or CSV, then upload it. You will see what changes before anything is saved.'),
            ],
        ], $arabic);

        return response()->download($path, 'students-template.xlsx')->deleteFileAfterSend();
    }

    public function issueCodes(IssueStudentCodes $issue, #[CurrentUser] User $user): RedirectResponse
    {
        $count = $issue->missing($user);
        Inertia::flash('toast', ['type' => 'success', 'message' => $count === 0
            ? __('Every student already has a code.')
            : __(':count students received a code.', ['count' => $count])]);

        return back();
    }

    public function replaceCode(Student $student, IssueStudentCodes $issue, #[CurrentUser] User $user): RedirectResponse
    {
        $issue->replace($user, $student);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('New code for :name. The old one no longer works.', ['name' => $student->full_name])]);

        return back();
    }

    /**
     * A section's codes, to print and hand out. Showing codes is recorded.
     */
    public function codes(Request $request, StudentCodes $codes, AuditLogger $audit, #[CurrentUser] User $user): Response
    {
        $stage = Stage::tryFrom($request->integer('stage')) ?? Stage::First;
        $section = Section::tryFrom((string) $request->string('section'));
        $section = $section !== null && $stage->hasSection($section) ? $section : $stage->sections()[0];

        $students = Student::query()
            ->where('stage', $stage->value)->where('section', $section->value)
            ->orderBy('full_name')->get();

        $audit->record('grades.student_codes_viewed', null, ['stage' => $stage->value, 'section' => $section->value], actor: $user);

        return Inertia::render('modules/grades/pages/print/codes', [
            'stage' => $stage->value,
            'section' => $section->value,
            'divided' => $stage->isDivided(),
            'studentLink' => route('grades.student.login'),
            'students' => array_values($students->map(fn (Student $student): array => [
                'name' => $student->full_name,
                'number' => $student->student_number,
                'code' => $student->code_encrypted === null ? null : $codes->decrypt($student->code_encrypted),
            ])->all()),
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
     * @return array{0: string, 1: Stage, 2: Section|null}
     */
    private function studentFields(Request $request): array
    {
        $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'stage' => ['required', Rule::enum(Stage::class)],
            'section' => ['nullable', Rule::enum(Section::class)],
        ]);
        $section = $request->input('section');

        return [
            (string) $request->string('name'),
            Stage::from($request->integer('stage')),
            is_string($section) && $section !== '' ? Section::from($section) : null,
        ];
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
