<?php

namespace App\Modules\Grades\Http;

use App\Models\User;
use App\Modules\Grades\Application\Actions\ChangeEntryWindow;
use App\Modules\Grades\Application\Actions\PublishResults;
use App\Modules\Grades\Application\Actions\SaveSubject;
use App\Modules\Grades\Application\Actions\SaveTeacher;
use App\Modules\Grades\Application\Queries\AccessBoard;
use App\Modules\Grades\Application\Queries\AppealCounts;
use App\Modules\Grades\Application\Queries\GradesBoard;
use App\Modules\Grades\Application\Queries\PortalLinks;
use App\Modules\Grades\Application\TeacherCodes;
use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\TeachingKind;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\GradeSettings;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\SubjectTeacher;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditLogger;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
    /**
     * The settings' tabs, each a page of its own (owner's request,
     * 2026-11-19); without one, /admin lists them.
     */
    public const TABS = ['window', 'links', 'teachers', 'students', 'permissions', 'card', 'teacher-view', 'subjects', 'appeals'];

    public function overview(Request $request, GradesBoard $board, AccessBoard $access, #[CurrentUser] User $user): Response
    {
        $canManage = $user->can('grades.manage');
        $tab = $request->query('tab');

        return Inertia::render('modules/grades/pages/admin/overview', [
            // An unknown tab is no tab: the list of tabs.
            'tab' => is_string($tab) && in_array($tab, self::TABS, true) ? $tab : null,
            ...$board->show(),
            // Sign-ins and mark editing (ADR-0037), for those who may change them.
            'access' => $canManage ? $access->show() : null,
            // What a result says and shows (ADR-0039).
            'resultSettings' => $canManage ? self::resultSettings(GradeSettings::current()) : null,
            // Students' appeals at a glance (appeals tab, 2026-11-20).
            'appealCounts' => $canManage ? AppealCounts::for(Period::Month1) : null,
            'teacherLink' => PortalLinks::teacher(),
            'studentLink' => PortalLinks::student(),
            'adminLink' => PortalLinks::admin(),
            'canManage' => $canManage,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function resultSettings(GradeSettings $settings): array
    {
        return [
            'pass_percent' => $settings->pass_percent,
            'exempt_subject_percent' => $settings->exempt_subject_percent,
            'exempt_overall_percent' => $settings->exempt_overall_percent,
            'bands' => $settings->bands()->minimums,
            'options' => $settings->options(),
            'tones' => $settings->tones(),
            // What teachers see of their students' results (ADR-0040).
            'teacher_results' => $settings->teacher_results->value,
            'teacher_view' => $settings->teacherView(),
            'appeal_minutes' => $settings->appeal_minutes,
            'appeal_from' => $settings->appeal_from?->toIso8601String(),
            'appeal_until' => $settings->appeal_until?->toIso8601String(),
            'appeal_scope' => $settings->appeal_scope->value,
            // Teachers may answer appeals of their subjects (ADR-0044).
            'teacher_appeal_replies' => $settings->teacher_appeal_replies,
            'absence_flag' => $settings->absence_flag,
        ];
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

    /** Shows or hides one section's results (ADR-0042). */
    public function publishSection(Request $request, PublishResults $publish, #[CurrentUser] User $user): RedirectResponse
    {
        $request->validate([
            'stage' => ['required', Rule::enum(Stage::class)],
            'section' => ['required', Rule::enum(Section::class)],
            'published' => ['required', 'boolean'],
        ]);
        $shown = $request->boolean('published');

        $publish->section($user, Period::Month1, Stage::from($request->integer('stage')), Section::from((string) $request->string('section')), $shown);
        Inertia::flash('toast', ['type' => 'success', 'message' => $shown
            ? __('This section\'s results are published to its students.')
            : __('This section\'s results are hidden from its students.')]);

        return back();
    }

    /**
     * Publishes the ticked stages and sections together (owner's request,
     * 2026-11-20). Each place is "stage|section", as the page names them.
     */
    public function publishSections(Request $request, PublishResults $publish, #[CurrentUser] User $user): RedirectResponse
    {
        $request->validate([
            'places' => ['required', 'array', 'min:1', 'max:12'],
            'places.*' => ['required', 'string', 'distinct', 'regex:/^[1-9]\|[A-Z]$/'],
        ]);

        $places = [];
        foreach ((array) $request->input('places') as $place) {
            [$stage, $section] = explode('|', (string) $place);
            $stage = Stage::tryFrom((int) $stage);
            $section = Section::tryFrom($section);
            if ($stage === null || $section === null) {
                throw ValidationException::withMessages(['places' => __('This stage has no such section.')]);
            }
            $places[] = [$stage, $section];
        }

        $publish->sections($user, Period::Month1, $places);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Results published to the students of the chosen sections (:count).', ['count' => count($places)])]);

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
     * number, and the subjects each teaches with their stage and sections.
     * Every subject is listed too, with each section's teacher, so a
     * teacher's subjects and sections can be chosen in their form (owner's
     * request, 2026-11-18). The page shows the employee numbers, so each
     * visit is audited.
     */
    public function teachers(TeacherCodes $codes, AuditLogger $audit, #[CurrentUser] User $user): Response
    {
        $teachers = Teacher::query()->orderBy('name')->get();
        $subjects = Subject::query()->with(['assignments.teacher:id,uuid', 'assignments.assistant:id,uuid'])
            ->orderBy('stage')->orderBy('sort_order')->orderBy('name')->get();

        if ($teachers->isNotEmpty()) {
            $audit->record('grades.teacher_codes_viewed', null, [], actor: $user);
        }

        return Inertia::render('modules/grades/pages/admin/teachers', [
            'teachers' => array_values($teachers->map(fn (Teacher $teacher): array => [
                'uuid' => $teacher->uuid,
                'name' => $teacher->name,
                'specialty' => $teacher->specialty,
                'code' => $teacher->code_encrypted === null ? null : $codes->decrypt($teacher->code_encrypted),
                'active' => $teacher->is_active,
                'last_seen_at' => $teacher->last_seen_at?->toIso8601String(),
            ])->all()),
            'subjects' => array_values($subjects->map(function (Subject $subject): array {
                $sections = array_map(fn (Section $section): string => $section->value, $subject->stage->sections());
                // One value per section of the stage, from its row (null: no teacher).
                $each = fn (callable $value): array => array_combine($sections, array_map(
                    fn (string $section): mixed => ($row = $subject->assignments->first(fn (SubjectTeacher $row): bool => $row->section->value === $section)) === null ? null : $value($row),
                    $sections,
                ));

                return [
                    'uuid' => $subject->uuid,
                    'name' => $subject->name,
                    'stage' => $subject->stage->value,
                    'divided' => $subject->stage->isDivided(),
                    'sections' => $sections,
                    // Each section's teacher, how it is taught and the
                    // teacher's assistant (owner's request, 2026-11-18).
                    'teachers' => $each(fn (SubjectTeacher $row): ?string => $row->teacher?->uuid),
                    'kinds' => $each(fn (SubjectTeacher $row): ?string => $row->kind?->value),
                    'assistants' => $each(fn (SubjectTeacher $row): ?string => $row->assistant?->uuid),
                ];
            })->all()),
        ]);
    }

    /**
     * Every teacher on a slip to print and hand out (owner's request,
     * 2026-11-16), like the students' code slips: name, specialty, each
     * subject with its stage and the sections they teach, the link and the
     * code. Showing the codes is audited.
     */
    public function printTeachers(TeacherCodes $codes, AuditLogger $audit, #[CurrentUser] User $user): Response
    {
        $teachers = Teacher::query()->with(['assignments.subject', 'assignments.assistant:id,name'])->orderBy('name')->get();

        $audit->record('grades.teacher_codes_viewed', null, ['printed' => true], actor: $user);

        return Inertia::render('modules/grades/pages/print/teachers', [
            'teacherLink' => PortalLinks::teacher(),
            'teachers' => array_values($teachers->map(fn (Teacher $teacher): array => [
                'uuid' => $teacher->uuid,
                'name' => $teacher->name,
                'specialty' => $teacher->specialty,
                'code' => $teacher->code_encrypted === null ? null : $codes->decrypt($teacher->code_encrypted),
                'subjects' => $this->taught($teacher),
            ])->all()),
        ]);
    }

    public function storeTeacher(Request $request, SaveTeacher $save, SaveSubject $subjects, #[CurrentUser] User $user): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:120'],
            // Made by the application when not given (owner, 2026-11-16).
            'code' => ['sometimes', 'nullable', 'string', 'max:255'],
            'specialty' => ['sometimes', 'nullable', 'string', 'max:255'],
            ...$this->subjectRules(),
        ]);

        $save->atomically(function () use ($request, $save, $subjects, $user): void {
            $teacher = $save->create(
                $user,
                (string) $request->string('name'),
                $request->filled('code') ? (string) $request->string('code') : null,
                $request->filled('specialty') ? (string) $request->string('specialty') : null,
            );
            if ($request->has('subjects')) {
                $this->assignSubjects($user, $teacher, $this->subjectChoices($request), $subjects);
            }
            $this->describeSubjects($user, $teacher, $request, $subjects);
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
            'specialty' => ['sometimes', 'nullable', 'string', 'max:255'],
            ...$this->subjectRules(),
        ]);

        $save->atomically(function () use ($request, $teacher, $save, $subjects, $user): void {
            if ($request->has('name')) {
                $save->rename($user, $teacher, (string) $request->string('name'));
            }
            if ($request->has('code')) {
                $save->changeCode($user, $teacher, (string) $request->string('code'));
            }
            if ($request->exists('specialty')) {
                $save->changeSpecialty($user, $teacher, $request->filled('specialty') ? (string) $request->string('specialty') : null);
            }
            if ($request->has('active')) {
                $save->setActive($user, $teacher, $request->boolean('active'));
            }
            if ($request->has('subjects')) {
                $this->assignSubjects($user, $teacher, $this->subjectChoices($request), $subjects);
            }
            $this->describeSubjects($user, $teacher, $request, $subjects);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Changes saved.')]);

        return to_route('grades.admin.teachers');
    }

    /**
     * The teacher's subjects in stage order, with only the sections they
     * teach; sections taught the same way share one entry.
     *
     * @return list<array{name: string, stage: int, divided: bool, sections: list<string>, kind: string|null, assistant: string|null}>
     */
    private function taught(Teacher $teacher): array
    {
        // Sections of a subject taught the same way (type and assistant)
        // share one block on the slip (owner's request, 2026-11-18).
        $groups = $teacher->assignments->groupBy(
            fn (SubjectTeacher $row): string => $row->subject_id.'|'.($row->kind->value ?? '').'|'.($row->assistant_id ?? ''),
        );

        $subjects = [];
        foreach ($groups as $rows) {
            $first = $rows->first();
            $subject = $first?->subject;
            if ($first === null || $subject === null) {
                continue;
            }
            $own = $rows->map(fn (SubjectTeacher $row): Section => $row->section)->all();
            $sections = array_values(array_filter($subject->stage->sections(), fn (Section $section): bool => in_array($section, $own, true)));
            $subjects[] = [[$subject->stage->value, $subject->sort_order, $subject->name, $sections[0]->value ?? ''], [
                'name' => $subject->name,
                'stage' => $subject->stage->value,
                'divided' => $subject->stage->isDivided(),
                'sections' => array_map(fn (Section $section): string => $section->value, $sections),
                // Null: not stated, left blank to fill in by hand.
                'kind' => $first->kind?->value,
                'assistant' => $first->assistant?->name,
            ]];
        }

        usort($subjects, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return array_map(fn (array $pair): array => $pair[1], $subjects);
    }

    /**
     * A form sends `subjects[]` with an empty first value, so choosing none
     * still says "no subjects" rather than "not changed". Each value is a
     * subject's UUID and one section ("<uuid>:B"), or the UUID alone for
     * every section of its stage (owner's request, 2026-11-18).
     *
     * @return array<string, list<mixed>>
     */
    private function subjectRules(): array
    {
        return [
            'subjects' => ['sometimes', 'array', 'max:200'],
            'subjects.*' => ['nullable', 'string', 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(:[AB])?$/i'],
            // How each chosen section is taught, keyed "<uuid>:<section>"
            // (owner's request, 2026-11-18).
            'details' => ['sometimes', 'array', 'max:200'],
            'details.*' => ['array:kind,assistant'],
            'details.*.kind' => ['nullable', Rule::enum(TeachingKind::class)],
            'details.*.assistant' => ['nullable', 'uuid', Rule::exists('grade_teachers', 'uuid')],
        ];
    }

    /**
     * Sets the type and the assistant of each section the teacher now
     * teaches that the form describes; a section they do not teach is
     * ignored (its fields are not sent once its box is unticked).
     */
    private function describeSubjects(User $user, Teacher $teacher, Request $request, SaveSubject $subjects): void
    {
        $details = (array) $request->input('details', []);
        if ($details === []) {
            return;
        }

        $assistants = Teacher::query()
            ->whereIn('uuid', array_filter(array_map(fn (mixed $detail): mixed => is_array($detail) ? ($detail['assistant'] ?? null) : null, $details), 'is_string'))
            ->get()->keyBy('uuid');

        foreach (SubjectTeacher::query()->with('subject')->where('teacher_id', $teacher->id)->get() as $row) {
            $detail = $details[$row->subject?->uuid.':'.$row->section->value] ?? null;
            if ($row->subject === null || ! is_array($detail)) {
                continue;
            }

            $kind = is_string($detail['kind'] ?? null) && $detail['kind'] !== '' ? TeachingKind::from($detail['kind']) : null;
            $assistant = is_string($detail['assistant'] ?? null) ? $assistants->get($detail['assistant']) : null;
            $subjects->describe($user, $row->subject, $row->section, $kind, $assistant);
        }
    }

    /**
     * @return list<array{0: string, 1: Section|null}>
     */
    private function subjectChoices(Request $request): array
    {
        $choices = [];
        foreach ((array) $request->input('subjects', []) as $value) {
            if (is_string($value) && $value !== '') {
                $parts = explode(':', $value, 2);
                $choices[] = [strtolower($parts[0]), isset($parts[1]) ? Section::from(strtoupper($parts[1])) : null];
            }
        }

        return $choices;
    }

    /**
     * The teacher teaches exactly these sections: each chosen one is given
     * to them (from whoever had it), the others they had are left without
     * a teacher. Each change is SaveSubject::assign, audited.
     *
     * @param  list<array{0: string, 1: Section|null}>  $choices
     */
    private function assignSubjects(User $user, Teacher $teacher, array $choices, SaveSubject $subjects): void
    {
        $found = Subject::query()->whereIn('uuid', array_unique(array_column($choices, 0)))->get()->keyBy('uuid');

        $wanted = [];
        foreach ($choices as [$uuid, $section]) {
            $subject = $found->get($uuid)
                ?? throw ValidationException::withMessages(['subjects' => __('A chosen subject no longer exists. Reload the page.')]);

            foreach ($section === null ? $subject->stage->sections() : [$section] as $each) {
                $subjects->assign($user, $subject, $teacher, $each);
                $wanted[$subject->id.'|'.$each->value] = true;
            }
        }

        foreach (SubjectTeacher::query()->with('subject')->where('teacher_id', $teacher->id)->get() as $row) {
            if ($row->subject !== null && ! isset($wanted[$row->subject_id.'|'.$row->section->value])) {
                $subjects->assign($user, $row->subject, null, $row->section);
            }
        }
    }

    /**
     * A new code made by the application, after the owner confirms; the
     * old one stops working at once.
     */
    public function regenerateTeacherCode(Teacher $teacher, SaveTeacher $save, #[CurrentUser] User $user): RedirectResponse
    {
        $save->regenerateCode($user, $teacher);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('New code for :name. The old one no longer works.', ['name' => $teacher->name])]);

        return to_route('grades.admin.teachers');
    }

    public function destroyTeacher(Teacher $teacher, SaveTeacher $save, #[CurrentUser] User $user): RedirectResponse
    {
        $save->delete($user, $teacher);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Teacher deleted.')]);

        return to_route('grades.admin.teachers');
    }

    public function subjects(): Response
    {
        $subjects = Subject::query()
            ->orderBy('stage')->orderBy('sort_order')->orderBy('name')->get();

        return Inertia::render('modules/grades/pages/admin/subjects', [
            'stages' => array_map(fn (Stage $stage): array => [
                'stage' => $stage->value,
                'subjects' => array_values($subjects->filter(fn (Subject $s): bool => $s->stage === $stage)->map(fn (Subject $subject): array => [
                    'uuid' => $subject->uuid,
                    'name' => $subject->name,
                    // Out of what, and its own pass and exemption marks (ADR-0039).
                    'max_mark' => $subject->max_mark,
                    'pass_mark' => $subject->pass_mark,
                    'exempt_mark' => $subject->exempt_mark,
                ])->all()),
            ], Stage::cases()),
            // No teachers here: a teacher's subjects are chosen on the
            // teachers' page (owner's request, 2026-11-17).
        ]);
    }

    /**
     * Each stage with its subjects, to print (owner's request, 2026-11-17).
     */
    public function printSubjects(): Response
    {
        $subjects = Subject::query()->orderBy('stage')->orderBy('sort_order')->orderBy('name')->get(['stage', 'name']);

        return Inertia::render('modules/grades/pages/print/subjects', [
            'stages' => array_map(fn (Stage $stage): array => [
                'stage' => $stage->value,
                'subjects' => array_values($subjects->filter(fn (Subject $s): bool => $s->stage === $stage)
                    ->map(fn (Subject $subject): string => $subject->name)->all()),
            ], Stage::cases()),
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
            'max_mark' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.Mark::CEILING],
            'pass_mark' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.Mark::CEILING],
            'exempt_mark' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.Mark::CEILING],
        ]);

        if ($request->has('max_mark')) {
            $save->changeScale(
                $user,
                $subject,
                $request->integer('max_mark'),
                $request->filled('pass_mark') ? $request->integer('pass_mark') : null,
                $request->filled('exempt_mark') ? $request->integer('exempt_mark') : null,
            );
        }
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
