<?php

namespace App\Modules\Grades\Http;

use App\Models\User;
use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Application\Queries\ResultSheets;
use App\Modules\Grades\Application\Queries\TeacherSheets;
use App\Modules\Grades\Application\ResultsWorkbook;
use App\Modules\Grades\Application\StudentCodes;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
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
    public function printStudent(Student $student, StudentCodes $codes): Response
    {
        return Inertia::render('modules/grades/pages/print/student', [
            ...$this->sheets->student($student),
            'code' => $student->code_encrypted === null ? null : $codes->decrypt($student->code_encrypted),
        ]);
    }

    public function export(Request $request, ResultsWorkbook $workbook, AuditLogger $audit, #[CurrentUser] User $user): BinaryFileResponse
    {
        [$stage, $section] = $this->place($request);
        $sheet = $this->sheets->section($stage, $section);
        $path = storage_path('app/private/exports/results-'.bin2hex(random_bytes(8)).'.xlsx');
        @mkdir(dirname($path), 0700, true);

        $workbook->write($path, $sheet, [
            'title' => $stage->isDivided()
                ? __(':stage · section :section', ['stage' => $stage->label(), 'section' => $section->value])
                : $stage->label(),
            'number' => __('Number'),
            'name' => __('Name'),
            'total' => __('Total out of :max', ['max' => $sheet['students'][0]['maximum'] ?? count($sheet['subjects']) * 100]),
            'percentage' => __('Percentage'),
            'below' => __('Below :mark', ['mark' => $sheet['pass_mark']]),
            'absences' => __('Absences'),
            'absent' => __('Absent'),
            'incomplete' => __('Incomplete'),
        ], App::getLocale() === 'ar');

        $audit->record('grades.results_exported', null, ['stage' => $stage->value, 'section' => $section->value], actor: $user);

        return response()
            ->download($path, "results-stage{$stage->value}-{$section->value}.xlsx")
            ->deleteFileAfterSend();
    }

    public function sheet(Subject $subject, string $section, TeacherSheets $sheets): Response
    {
        abort_unless($subject->stage->hasSection(Section::from($section)), 404);

        return Inertia::render('modules/grades/pages/admin/sheet', $sheets->section($subject, Section::from($section)));
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
