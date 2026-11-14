<?php

namespace App\Modules\Grades\Http;

use App\Models\User;
use App\Modules\Grades\Application\Actions\ChangeEntryWindow;
use App\Modules\Grades\Application\Actions\SaveSubject;
use App\Modules\Grades\Application\Actions\SaveTeacher;
use App\Modules\Grades\Application\Queries\GradesBoard;
use App\Modules\Grades\Application\TeacherCodes;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The owner's set-up of the department (ADR-0034): the overview with the
 * entry window, teachers and their codes, and subjects with their teachers.
 * Each change calls one action, which re-checks the permission inside its
 * transaction; the routes check it first (can:grades.manage).
 */
class GradesAdminController
{
    public function overview(GradesBoard $board, #[CurrentUser] User $user): Response
    {
        return Inertia::render('modules/grades/pages/admin/overview', [
            ...$board->show(),
            'teacherLink' => route('grades.teacher.login'),
            'canManage' => $user->can('grades.manage'),
        ]);
    }

    public function window(Request $request, ChangeEntryWindow $window, #[CurrentUser] User $user): RedirectResponse
    {
        $request->validate(['status' => ['required', Rule::enum(WindowStatus::class)]]);
        $target = WindowStatus::from((string) $request->string('status'));

        $window->handle($user, Period::Month1, $target);
        Inertia::flash('toast', ['type' => 'success', 'message' => $target === WindowStatus::Open
            ? __('Mark entry is open. Teachers can enter marks.')
            : __('Mark entry is closed. Teachers can no longer change marks.')]);

        return back();
    }

    public function teachers(): Response
    {
        $teachers = Teacher::query()->with(['subjects' => fn ($query) => $query->orderBy('stage')->orderBy('sort_order')])
            ->orderBy('name')->get();

        return Inertia::render('modules/grades/pages/admin/teachers', [
            'teachers' => array_values($teachers->map(fn (Teacher $teacher): array => [
                'uuid' => $teacher->uuid,
                'name' => $teacher->name,
                'active' => $teacher->is_active,
                'last_seen_at' => $teacher->last_seen_at?->toIso8601String(),
                'subjects' => array_values($teacher->subjects->map(fn (Subject $subject): string => $subject->name.' ('.$subject->stage->value.')')->all()),
            ])->all()),
            'teacherLink' => route('grades.teacher.login'),
            'codeLength' => ['min' => TeacherCodes::MIN_LENGTH, 'max' => TeacherCodes::MAX_LENGTH],
        ]);
    }

    public function storeTeacher(Request $request, SaveTeacher $save, #[CurrentUser] User $user): RedirectResponse
    {
        $request->validate(['name' => ['required', 'string', 'max:120'], 'code' => ['required', 'string', 'max:255']]);

        $save->create($user, (string) $request->string('name'), (string) $request->string('code'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Teacher added. Send them the link and their code.')]);

        return to_route('grades.admin.teachers');
    }

    public function updateTeacher(Request $request, Teacher $teacher, SaveTeacher $save, #[CurrentUser] User $user): RedirectResponse
    {
        $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'code' => ['sometimes', 'required', 'string', 'max:255'],
            'active' => ['sometimes', 'required', 'boolean'],
        ]);

        if ($request->has('name')) {
            $save->rename($user, $teacher, (string) $request->string('name'));
        }
        if ($request->has('code')) {
            $save->changeCode($user, $teacher, (string) $request->string('code'));
        }
        if ($request->has('active')) {
            $save->setActive($user, $teacher, $request->boolean('active'));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Changes saved.')]);

        return to_route('grades.admin.teachers');
    }

    public function subjects(): Response
    {
        $subjects = Subject::query()->with('teacher:id,uuid,name')
            ->orderBy('stage')->orderBy('sort_order')->orderBy('name')->get();

        return Inertia::render('modules/grades/pages/admin/subjects', [
            'stages' => array_map(fn (Stage $stage): array => [
                'stage' => $stage->value,
                'subjects' => array_values($subjects->filter(fn (Subject $s): bool => $s->stage === $stage)->map(fn (Subject $subject): array => [
                    'uuid' => $subject->uuid,
                    'name' => $subject->name,
                    'teacher' => $subject->teacher?->uuid,
                ])->all()),
            ], Stage::cases()),
            // All teachers, stopped ones marked: a subject whose teacher was
            // stopped must still show them, or saving would unassign them.
            'teachers' => array_values(Teacher::query()->orderBy('name')->get(['uuid', 'name', 'is_active'])
                ->map(fn (Teacher $teacher): array => ['uuid' => $teacher->uuid, 'name' => $teacher->name, 'active' => $teacher->is_active])->all()),
            'expected' => (int) config('grades.subjects_per_stage'),
        ]);
    }

    public function storeSubject(Request $request, SaveSubject $save, #[CurrentUser] User $user): RedirectResponse
    {
        $request->validate([
            'stage' => ['required', Rule::enum(Stage::class)],
            'name' => ['required', 'string', 'max:120'],
            'teacher' => ['nullable', 'uuid', Rule::exists('grade_teachers', 'uuid')],
        ]);

        $save->create($user, Stage::from($request->integer('stage')), (string) $request->string('name'), $this->teacherFrom($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Subject added.')]);

        return to_route('grades.admin.subjects');
    }

    public function updateSubject(Request $request, Subject $subject, SaveSubject $save, #[CurrentUser] User $user): RedirectResponse
    {
        $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'teacher' => ['sometimes', 'nullable', 'uuid', Rule::exists('grade_teachers', 'uuid')],
        ]);

        if ($request->has('name')) {
            $save->rename($user, $subject, (string) $request->string('name'));
        }
        if ($request->exists('teacher')) {
            $save->assign($user, $subject, $this->teacherFrom($request));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Changes saved.')]);

        return to_route('grades.admin.subjects');
    }

    private function teacherFrom(Request $request): ?Teacher
    {
        $uuid = $request->input('teacher');

        return is_string($uuid) && $uuid !== '' ? Teacher::query()->where('uuid', $uuid)->firstOrFail() : null;
    }
}
