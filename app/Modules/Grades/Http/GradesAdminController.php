<?php

namespace App\Modules\Grades\Http;

use App\Models\User;
use App\Modules\Grades\Application\Actions\ChangeEntryWindow;
use App\Modules\Grades\Application\Actions\PublishResults;
use App\Modules\Grades\Application\Actions\SaveSubject;
use App\Modules\Grades\Application\Actions\SaveTeacher;
use App\Modules\Grades\Application\Queries\GradesBoard;
use App\Modules\Grades\Application\TeacherCodes;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditLogger;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The owner's set-up of the department (ADR-0034): the overview with the
 * entry window, teachers and their codes (their employee numbers), and
 * subjects with their teachers.
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
            'studentLink' => route('grades.student.login'),
            'adminLink' => route('grades.admin.overview'),
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

    public function publish(Request $request, PublishResults $publish, #[CurrentUser] User $user): RedirectResponse
    {
        $request->validate(['published' => ['required', 'boolean']]);
        $shown = $request->boolean('published');

        $publish->handle($user, Period::Month1, $shown);
        Inertia::flash('toast', ['type' => 'success', 'message' => $shown
            ? __('Results published. Students can see their own result with their code.')
            : __('Results hidden from the students.')]);

        return back();
    }

    /**
     * Teachers in one table (owner's request, 2026-11-16): name, employee
     * number, and the subjects each teaches with their stage. Every subject
     * is listed too, so a teacher's subjects can be chosen in their form.
     * The page shows the employee numbers, so each visit is audited.
     */
    public function teachers(TeacherCodes $codes, AuditLogger $audit, #[CurrentUser] User $user): Response
    {
        $teachers = Teacher::query()->orderBy('name')->get();
        $subjects = Subject::query()->with('teacher:id,uuid')
            ->orderBy('stage')->orderBy('sort_order')->orderBy('name')->get();

        if ($teachers->isNotEmpty()) {
            $audit->record('grades.teacher_codes_viewed', null, [], actor: $user);
        }

        return Inertia::render('modules/grades/pages/admin/teachers', [
            'teachers' => array_values($teachers->map(fn (Teacher $teacher): array => [
                'uuid' => $teacher->uuid,
                'name' => $teacher->name,
                'code' => $teacher->code_encrypted === null ? null : $codes->decrypt($teacher->code_encrypted),
                'active' => $teacher->is_active,
                'last_seen_at' => $teacher->last_seen_at?->toIso8601String(),
            ])->all()),
            'subjects' => array_values($subjects->map(fn (Subject $subject): array => [
                'uuid' => $subject->uuid,
                'name' => $subject->name,
                'stage' => $subject->stage->value,
                'divided' => $subject->stage->isDivided(),
                'sections' => array_map(fn (Section $section): string => $section->value, $subject->stage->sections()),
                'teacher' => $subject->teacher?->uuid,
            ])->all()),
            'codeLength' => ['min' => TeacherCodes::MIN_LENGTH, 'max' => TeacherCodes::MAX_LENGTH],
        ]);
    }

    public function storeTeacher(Request $request, SaveTeacher $save, SaveSubject $subjects, #[CurrentUser] User $user): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:255'],
            ...$this->subjectRules(),
        ]);

        DB::transaction(function () use ($request, $save, $subjects, $user): void {
            $teacher = $save->create($user, (string) $request->string('name'), (string) $request->string('code'));
            if ($request->has('subjects')) {
                $this->assignSubjects($user, $teacher, $this->subjectUuids($request), $subjects);
            }
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Teacher added. Send them the link and their code.')]);

        return to_route('grades.admin.teachers');
    }

    public function updateTeacher(Request $request, Teacher $teacher, SaveTeacher $save, SaveSubject $subjects, #[CurrentUser] User $user): RedirectResponse
    {
        $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'code' => ['sometimes', 'required', 'string', 'max:255'],
            'active' => ['sometimes', 'required', 'boolean'],
            ...$this->subjectRules(),
        ]);

        DB::transaction(function () use ($request, $teacher, $save, $subjects, $user): void {
            if ($request->has('name')) {
                $save->rename($user, $teacher, (string) $request->string('name'));
            }
            if ($request->has('code')) {
                $save->changeCode($user, $teacher, (string) $request->string('code'));
            }
            if ($request->has('active')) {
                $save->setActive($user, $teacher, $request->boolean('active'));
            }
            if ($request->has('subjects')) {
                $this->assignSubjects($user, $teacher, $this->subjectUuids($request), $subjects);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Changes saved.')]);

        return to_route('grades.admin.teachers');
    }

    /**
     * A form sends `subjects[]` with an empty first value, so choosing none
     * still says "no subjects" rather than "not changed".
     *
     * @return array<string, list<mixed>>
     */
    private function subjectRules(): array
    {
        return [
            'subjects' => ['sometimes', 'array', 'max:100'],
            'subjects.*' => ['nullable', 'uuid', Rule::exists('grade_subjects', 'uuid')],
        ];
    }

    /**
     * @return list<string>
     */
    private function subjectUuids(Request $request): array
    {
        return array_values(array_filter((array) $request->input('subjects', []), fn (mixed $uuid): bool => is_string($uuid) && $uuid !== ''));
    }

    /**
     * The teacher teaches exactly these subjects: each chosen one is given
     * to them (from whoever had it), the others they had are left without
     * a teacher. Each change is SaveSubject::assign, audited.
     *
     * @param  list<string>  $uuids
     */
    private function assignSubjects(User $user, Teacher $teacher, array $uuids, SaveSubject $subjects): void
    {
        foreach (Subject::query()->whereIn('uuid', $uuids)->get() as $subject) {
            $subjects->assign($user, $subject, $teacher);
        }

        foreach (Subject::query()->where('teacher_id', $teacher->id)->whereNotIn('uuid', $uuids)->get() as $subject) {
            $subjects->assign($user, $subject, null);
        }
    }

    public function destroyTeacher(Teacher $teacher, SaveTeacher $save, #[CurrentUser] User $user): RedirectResponse
    {
        $save->delete($user, $teacher);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Teacher deleted.')]);

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
            'stage' => ['sometimes', 'required', Rule::enum(Stage::class)],
        ]);

        if ($request->has('stage')) {
            $save->changeStage($user, $subject, Stage::from($request->integer('stage')));
        }
        if ($request->has('name')) {
            $save->rename($user, $subject, (string) $request->string('name'));
        }
        if ($request->exists('teacher')) {
            $save->assign($user, $subject, $this->teacherFrom($request));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Changes saved.')]);

        return to_route('grades.admin.subjects');
    }

    public function destroySubject(Subject $subject, SaveSubject $save, #[CurrentUser] User $user): RedirectResponse
    {
        $save->delete($user, $subject);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Subject deleted.')]);

        return to_route('grades.admin.subjects');
    }

    private function teacherFrom(Request $request): ?Teacher
    {
        $uuid = $request->input('teacher');

        return is_string($uuid) && $uuid !== '' ? Teacher::query()->where('uuid', $uuid)->firstOrFail() : null;
    }
}
