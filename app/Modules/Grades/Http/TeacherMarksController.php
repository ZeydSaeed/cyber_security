<?php

namespace App\Modules\Grades\Http;

use App\Modules\Grades\Application\Actions\ApproveSection;
use App\Modules\Grades\Application\Actions\ManageAppeals;
use App\Modules\Grades\Application\Actions\RequestEdit;
use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Application\Queries\MarkHistory;
use App\Modules\Grades\Application\Queries\TeacherSheets;
use App\Modules\Grades\Application\SectionAccess;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Models\GradeAppeal;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\GradeSettings;
use App\Modules\Grades\Models\SectionApproval;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\SubjectTeacher;
use App\Modules\Grades\Models\Teacher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A teacher's pages (ADR-0034): their subjects, and one section's sheet
 * to fill in. A section another teacher teaches is a 404, not a 403, so
 * subject addresses cannot be probed.
 */
class TeacherMarksController
{
    public function __construct(private readonly TeacherSheets $sheets) {}

    public function subjects(Request $request): Response
    {
        $teacher = $this->teacher();

        return Inertia::render('modules/grades/pages/teacher/subjects', [
            'portal' => ['name' => $teacher->name, 'logout' => route('grades.teacher.logout')],
            ...$this->sheets->subjects($teacher, Period::Month1, TeacherSession::settings($request)),
        ]);
    }

    /**
     * Every section of one subject the teacher teaches, on one page: the
     * students straight away, section A's sheet then section B's (owner's
     * request, 2026-11-19).
     */
    public function subject(Request $request, Subject $subject, MarkHistory $history): Response
    {
        $teacher = $this->teacher();
        $settings = TeacherSession::settings($request);
        $window = GradePeriod::for(Period::Month1);

        $rows = SubjectTeacher::query()
            ->where('subject_id', $subject->id)
            ->where('teacher_id', $teacher->id)
            ->get()
            ->reject(fn (SubjectTeacher $row): bool => ! $subject->stage->hasSection($row->section) || SectionAccess::hidden($teacher, $row, $settings))
            ->keyBy(fn (SubjectTeacher $row): string => $row->section->value);

        // Not this teacher's subject: a 404, as for a section (ADR-0034).
        if ($rows->isEmpty()) {
            throw new NotFoundHttpException;
        }

        $sections = [];
        foreach ($subject->stage->sections() as $place) {
            $row = $rows->get($place->value);
            if ($row === null) {
                continue;
            }
            $approval = SectionApproval::find($subject, $place, Period::Month1);
            $policy = SectionAccess::policy($teacher, $row, $settings, $window, $approval !== null, $window->publishedFor($subject->stage, $place));
            $sections[] = $this->sheets->section($subject, $place, Period::Month1, $policy, $teacher, $approval, $settings->teacher_appeal_replies);
        }

        return Inertia::render('modules/grades/pages/teacher/subject', [
            'portal' => ['name' => $teacher->name, 'logout' => route('grades.teacher.logout')],
            'subject' => $sections[0]['subject'],
            'open' => $sections[0]['open'],
            'sections' => array_map(fn (array $sheet): array => array_diff_key($sheet, ['subject' => true, 'open' => true]), $sections),
            // Each section's mark journeys (ADR-0043), only once asked for.
            'history' => Inertia::optional(fn (): array => array_combine(
                array_column($sections, 'section'),
                array_map(fn (array $sheet): array => $history->for($subject, Section::from($sheet['section']), Period::Month1), $sections),
            )),
        ]);
    }

    public function section(Request $request, Subject $subject, string $section, MarkHistory $history): Response
    {
        $teacher = $this->teacher();
        $settings = TeacherSession::settings($request);
        $row = $this->ensureOwn($teacher, $subject, $section, $settings);
        // Read once, for the policy and the page (ADR-0041).
        $approval = SectionApproval::find($subject, Section::from($section), Period::Month1);
        $window = GradePeriod::for(Period::Month1);
        $policy = SectionAccess::policy($teacher, $row, $settings, $window, $approval !== null, $window->publishedFor($subject->stage, Section::from($section)));

        return Inertia::render('modules/grades/pages/teacher/section', [
            'portal' => ['name' => $teacher->name, 'logout' => route('grades.teacher.logout')],
            ...$this->sheets->section($subject, Section::from($section), Period::Month1, $policy, $teacher, $approval, $settings->teacher_appeal_replies),
            // Each mark's journey (ADR-0043): read only when the teacher
            // opens it, so the sheet keeps its query budget.
            'history' => Inertia::optional(fn (): array => $history->for($subject, Section::from($section), Period::Month1)),
        ]);
    }

    public function save(SaveMarksRequest $request, Subject $subject, string $section, SaveMarks $save): RedirectResponse
    {
        $teacher = $this->teacher();
        $this->ensureOwn($teacher, $subject, $section, TeacherSession::settings($request));

        $result = $save->handle($teacher, $subject, Section::from($section), Period::Month1, $request->marks());

        $message = match (true) {
            $result['changed'] === 0 => __('Nothing changed.'),
            $result['complete'] => __('Saved. Every student in this section has a mark.'),
            default => __('Saved.'),
        };
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return $this->backTo($subject, $section);
    }

    /**
     * Asks the owner to let the teacher change this section's marks again
     * (ADR-0037).
     */
    public function requestEdit(Request $request, Subject $subject, string $section, RequestEdit $edit): RedirectResponse
    {
        $teacher = $this->teacher();
        $this->ensureOwn($teacher, $subject, $section, TeacherSession::settings($request));
        $request->validate(['reason' => ['required', 'string', 'max:'.RequestEdit::MAX_REASON * 2]]);

        $edit->handle($teacher, $subject, Section::from($section), Period::Month1, (string) $request->string('reason'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Request sent. The department will answer it.')]);

        return $this->backTo($subject, $section);
    }

    /** Answers an appeal of a section the teacher teaches, when allowed (ADR-0044). */
    public function answerAppeal(Request $request, GradeAppeal $appeal, ManageAppeals $appeals): RedirectResponse
    {
        $teacher = $this->teacher();
        $request->validate([
            'decision' => ['required', Rule::in(['accept', 'reject'])],
            'response' => ['nullable', 'string', 'max:'.ManageAppeals::MAX_TEXT],
        ]);

        $appeals->answerAsTeacher(
            $teacher,
            $appeal,
            $request->string('decision')->toString() === 'accept',
            $request->filled('response') ? (string) $request->string('response') : null,
        );
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your answer was sent to the student. The department was told.')]);

        return back();
    }

    /** Approves this section's marks (ADR-0041): the sheet then locks. */
    public function approve(Request $request, Subject $subject, string $section, ApproveSection $approve): RedirectResponse
    {
        $teacher = $this->teacher();
        $this->ensureOwn($teacher, $subject, $section, TeacherSession::settings($request));

        $approve->approve($teacher, $subject, Section::from($section), Period::Month1);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Marks approved. They are now locked.')]);

        return $this->backTo($subject, $section);
    }

    /**
     * Back to the page the teacher acted from: the whole subject's page
     * (owner's request, 2026-11-19), or the single section's sheet.
     */
    private function backTo(Subject $subject, string $section): RedirectResponse
    {
        $page = route('grades.teacher.subject', ['subject' => $subject->uuid]);

        return url()->previous() === $page
            ? redirect()->to($page)
            : to_route('grades.teacher.section', ['subject' => $subject->uuid, 'section' => $section]);
    }

    private function teacher(): Teacher
    {
        $teacher = Auth::guard(Teacher::GUARD)->user();

        // TeacherSession guarantees it; this keeps the type exact.
        if (! $teacher instanceof Teacher) {
            throw new NotFoundHttpException;
        }

        return $teacher;
    }

    private function ensureOwn(Teacher $teacher, Subject $subject, string $section, GradeSettings $settings): SubjectTeacher
    {
        // A section the stage does not have, or one another teacher teaches.
        // A section blocked for the teacher (ADR-0037) is hidden the same way.
        $place = Section::from($section);
        $row = ! $subject->stage->hasSection($place) ? null : SubjectTeacher::query()
            ->where('subject_id', $subject->id)
            ->where('section', $place->value)
            ->where('teacher_id', $teacher->id)
            ->first();

        if ($row === null || SectionAccess::hidden($teacher, $row, $settings)) {
            throw new NotFoundHttpException;
        }

        return $row;
    }
}
