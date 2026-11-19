<?php

namespace App\Modules\Grades\Http;

use App\Models\User;
use App\Modules\Grades\Application\Actions\ApproveSection;
use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Application\Queries\MarkHistory;
use App\Modules\Grades\Application\Queries\ResultSheets;
use App\Modules\Grades\Application\Queries\TeacherSheets;
use App\Modules\Grades\Application\ResultsWorkbook;
use App\Modules\Grades\Application\ResultViews;
use App\Modules\Grades\Application\StudentCodes;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Models\GradeAppeal;
use App\Modules\Grades\Models\GradeSettings;
use App\Modules\Grades\Models\SectionApproval;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Platform\Audit\AuditLogger;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Results (ADR-0034): a section's sheet, printable pages for a section and
 * for one student, the Excel export, and the owner's corrections of marks
 * (the teachers' sheet, saved as the owner, at any time).
 */
class ResultsController
{
    public function __construct(private readonly ResultSheets $sheets) {}

    public function index(Request $request): Response
    {
        [$stage, $section] = $this->place($request);

        return Inertia::render('modules/grades/pages/admin/results', [
            ...$this->sheets->section($stage, $section),
            'places' => self::places(),
        ]);
    }

    /**
     * Every stage and section there is: the first stage once, the second
     * stage's two sections.
     *
     * @return list<array{stage: int, section: string, divided: bool}>
     */
    public static function places(): array
    {
        $places = [];
        foreach (Stage::cases() as $stage) {
            foreach ($stage->sections() as $section) {
                $places[] = ['stage' => $stage->value, 'section' => $section->value, 'divided' => $stage->isDivided()];
            }
        }

        return $places;
    }

    public function printSection(Request $request): Response
    {
        [$stage, $section] = $this->place($request);

        return Inertia::render('modules/grades/pages/print/section', $this->sheets->section($stage, $section));
    }

    /**
     * The owner's card shows the student's code as their ID (owner's
     * request, 2026-11-16): the card is handed to the student.
     */
    public function printStudent(Student $student, StudentCodes $codes, MarkHistory $history, ResultViews $views, #[CurrentUser] User $user): Response
    {
        $manage = $user->can('grades.manage');
        $views->byUser($student, $user);
        $appeals = GradeAppeal::query()->with(['subject:id,name', 'decidedByTeacher:id,name'])
            ->where('student_id', $student->id)->where('period', Period::Month1->value)
            ->orderByDesc('id')->get();
        $deciders = User::query()->whereIn('id', $appeals->pluck('decided_by_user_id')->filter()->unique())->pluck('name', 'id');

        return Inertia::render('modules/grades/pages/print/student', [
            ...$this->sheets->student($student),
            'code' => $student->code_encrypted === null ? null : $codes->decrypt($student->code_encrypted),
            // Below the card, not printed (ADR-0044): each mark's journey,
            // the student's appeals, and whether they may appeal.
            'journeys' => $history->forStudent($student),
            'appeals' => array_values($appeals->map(fn (GradeAppeal $appeal): array => [
                'uuid' => $appeal->uuid,
                'subject' => $appeal->subject?->name,
                'reason' => $appeal->reason,
                'status' => $appeal->status->value,
                'response' => $appeal->response,
                'by' => $appeal->decidedByTeacher->name ?? ($appeal->decided_by_user_id === null ? null : $deciders[$appeal->decided_by_user_id] ?? null),
                'by_teacher' => $appeal->decided_by_teacher_id !== null,
                'created_at' => $appeal->created_at->toIso8601String(),
            ])->all()),
            // Who saw the result, newest first (ADR-0045).
            'views' => $views->of($student),
            'appealAccess' => $manage ? [
                'scope' => GradeSettings::current()->appeal_scope->value,
                'allowed' => $student->appeal_allowed,
                'uuid' => $student->uuid,
            ] : null,
        ]);
    }

    public function export(Request $request, ResultsWorkbook $workbook, AuditLogger $audit, #[CurrentUser] User $user): BinaryFileResponse
    {
        [$stage, $section] = $this->place($request);
        $sheet = $this->sheets->section($stage, $section);
        $path = storage_path('app/private/exports/results-'.bin2hex(random_bytes(8)).'.xlsx');
        // Explicit, not @mkdir: a suppressed warning hides real failures
        // (and still surfaced as an error under tinker, review 2026-11-18).
        is_dir(dirname($path)) || mkdir(dirname($path), 0700, true);

        $workbook->write($path, $sheet, [
            'title' => $stage->isDivided()
                ? __(':stage · section :section', ['stage' => $stage->label(), 'section' => $section->value])
                : $stage->label(),
            'number' => __('Number'),
            'name' => __('Name'),
            'total' => __('Total out of :max', ['max' => array_sum(array_column($sheet['subjects'], 'max'))]),
            'percentage' => __('Percentage'),
            'below' => __('Failed subjects'),
            'absences' => __('Absences'),
            'absent' => __('Absent'),
            'incomplete' => __('Incomplete'),
            'rank' => __('Rank in the section'),
            'grade' => __('Overall grade'),
            'exempt' => __('Exempt'),
            'behaviour' => __('Behaviour'),
            'notes' => __('Notes'),
            'bands' => self::bandWords(),
            'behaviours' => self::behaviourWords(),
        ], App::getLocale() === 'ar');

        $audit->record('grades.results_exported', null, ['stage' => $stage->value, 'section' => $section->value], actor: $user);

        return response()
            ->download($path, "results-stage{$stage->value}-{$section->value}.xlsx")
            ->deleteFileAfterSend();
    }

    /** @return array<string, string> */
    public static function bandWords(): array
    {
        return [
            'excellent' => __('Excellent'),
            'very_good' => __('Very good'),
            'good' => __('Good'),
            'fair' => __('Fair'),
            'pass' => __('Pass'),
            'fail' => __('Fail'),
        ];
    }

    /** @return array<string, string> */
    public static function behaviourWords(): array
    {
        return [
            'excellent' => __('Excellent'),
            'very_good' => __('Very good'),
            'good' => __('Good'),
            'acceptable' => __('Acceptable'),
            'poor' => __('Poor'),
        ];
    }

    public function sheet(Subject $subject, string $section, TeacherSheets $sheets, MarkHistory $history): Response
    {
        abort_unless($subject->stage->hasSection(Section::from($section)), 404);

        $place = Section::from($section);
        $approval = SectionApproval::query()->with('teacher:id,name')
            ->where('subject_id', $subject->id)->where('section', $place->value)->where('period', Period::Month1->value)
            ->first();

        return Inertia::render('modules/grades/pages/admin/sheet', [
            ...$sheets->section($subject, $place, Period::Month1, null, null, $approval),
            // Every change of these marks, and the teacher's approval (ADR-0041).
            'history' => $history->for($subject, $place),
            'approval' => $approval === null ? null : [
                'at' => $approval->approved_at->toIso8601String(),
                'by' => $approval->teacher?->name,
            ],
        ]);
    }

    /** Reopens approved marks to their teacher (ADR-0041). */
    public function withdrawApproval(Subject $subject, string $section, ApproveSection $approve, #[CurrentUser] User $user): RedirectResponse
    {
        abort_unless($subject->stage->hasSection(Section::from($section)), 404);

        $approve->withdraw($user, $subject, Section::from($section), Period::Month1);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Approval withdrawn. The teacher can change the marks again.')]);

        return to_route('grades.admin.sheet', ['subject' => $subject->uuid, 'section' => $section]);
    }

    public function saveSheet(SaveMarksRequest $request, Subject $subject, string $section, SaveMarks $save, #[CurrentUser] User $user): RedirectResponse
    {
        abort_unless($subject->stage->hasSection(Section::from($section)), 404);

        $result = $save->handle($user, $subject, Section::from($section), Period::Month1, $request->marks());

        Inertia::flash('toast', ['type' => 'success', 'message' => $result['changed'] === 0 ? __('Nothing changed.') : __('Saved.')]);

        return to_route('grades.admin.sheet', ['subject' => $subject->uuid, 'section' => $section]);
    }

    /**
     * The stage and section asked for; the first of each when absent or wrong.
     *
     * @return array{0: Stage, 1: Section}
     */
    private function place(Request $request): array
    {
        $stage = Stage::tryFrom($request->integer('stage')) ?? Stage::First;
        $section = Section::tryFrom((string) $request->string('section'));

        return [$stage, $section !== null && $stage->hasSection($section) ? $section : $stage->sections()[0]];
    }
}
