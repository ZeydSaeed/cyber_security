<?php

namespace App\Modules\Grades\Http;

use App\Models\User;
use App\Modules\Grades\Application\Actions\IssueStudentCodes;
use App\Modules\Grades\Application\Actions\SaveStudent;
use App\Modules\Grades\Application\StudentCodes;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\StudentStatus;
use App\Modules\Grades\Models\GradeSettings;
use App\Modules\Grades\Models\Student;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tables\TableColumns;
use App\Platform\Tables\TableState;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Students (ADR-0034): the list, one student added, changed or deleted by
 * hand (SaveStudent), and their codes. The spreadsheet import was removed
 * on 2026-11-20 (owner's decision): no page had reached it since 2026-11-16.
 */
class StudentsAdminController
{
    /**
     * The list shows each student's code to those who manage the grades
     * (owner's request, 2026-11-16); each such showing is audited.
     */
    public function index(Request $request, StudentsTable $table, StudentCodes $codes, AuditLogger $audit, #[CurrentUser] User $viewer): Response
    {
        $state = TableState::from($table, $request->query());
        $students = $state->paginate($table, Student::query()->withCount(['devices' => fn ($query) => $query->where('expires_at', '>', now())]));
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
                    // Sign-ins and whether stopped (ADR-0037).
                    'logins' => $student->login_count,
                    'last_login_at' => $student->last_login_at?->toIso8601String(),
                    'blocked' => $student->access_blocked,
                    // May appeal when appeals are for chosen students (ADR-0043).
                    'appeal_allowed' => $student->appeal_allowed,
                    'devices' => (int) $student->getAttribute('devices_count'),
                    'code' => $showCodes && $student->code_encrypted !== null ? $codes->decrypt($student->code_encrypted) : null,
                    'name' => $student->full_name,
                    'number' => $student->student_number,
                    'stage' => $student->stage->value,
                    'section' => $student->section->value,
                    'status' => $student->status?->value,
                ])->all()),
                'page' => $students->currentPage(),
                'last_page' => $students->lastPage(),
                'total' => $students->total(),
                'from' => $students->firstItem(),
                'to' => $students->lastItem(),
            ],
            'state' => $state->toArray(),
            'columns' => TableColumns::for($viewer->id, $table),
            'canManage' => $showCodes,
            // Whether appeals are for chosen students only (ADR-0043).
            'appealScope' => GradeSettings::current()->appeal_scope->value,
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
        [$name, $stage, $section, $status, $number] = $this->studentFields($request);
        $save->update($user, $student, $name, $stage, $section, $status, $request->filled('code') ? (string) $request->string('code') : null, $number);
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
     * The Print page (owner's request, 2026-11-17): each group's code slips,
     * and codes for students who have none yet.
     */
    public function printMenu(): Response
    {
        return Inertia::render('modules/grades/pages/admin/print', [
            'places' => ResultsController::places(),
            'withoutCode' => Student::query()->whereNull('code_digest')->count(),
        ]);
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

    /**
     * @return array{0: string, 1: Stage, 2: Section|null, 3: StudentStatus|null, 4: string|null}
     */
    private function studentFields(Request $request): array
    {
        $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'stage' => ['required', Rule::enum(Stage::class)],
            'section' => ['nullable', Rule::enum(Section::class)],
            'status' => ['nullable', Rule::enum(StudentStatus::class)],
            'number' => ['nullable', 'string', 'max:40'],
        ]);
        $section = $request->input('section');
        $status = $request->input('status');

        return [
            (string) $request->string('name'),
            Stage::from($request->integer('stage')),
            is_string($section) && $section !== '' ? Section::from($section) : null,
            is_string($status) && $status !== '' ? StudentStatus::from($status) : null,
            $request->filled('number') ? (string) $request->string('number') : null,
        ];
    }
}
