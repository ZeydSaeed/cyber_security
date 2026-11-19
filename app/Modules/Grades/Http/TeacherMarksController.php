<?php

namespace App\Modules\Grades\Http;

use App\Modules\Grades\Application\Actions\ApproveSection;
use App\Modules\Grades\Application\Actions\RequestEdit;
use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Application\Queries\TeacherSheets;
use App\Modules\Grades\Application\SectionAccess;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\GradeSettings;
use App\Modules\Grades\Models\SectionApproval;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\SubjectTeacher;
use App\Modules\Grades\Models\Teacher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

    public function section(Request $request, Subject $subject, string $section): Response
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
            ...$this->sheets->section($subject, Section::from($section), Period::Month1, $policy, $teacher, $approval),
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

        return to_route('grades.teacher.section', ['subject' => $subject->uuid, 'section' => $section]);
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

        return to_route('grades.teacher.section', ['subject' => $subject->uuid, 'section' => $section]);
    }

    /** Approves this section's marks (ADR-0041): the sheet then locks. */
    public function approve(Request $request, Subject $subject, string $section, ApproveSection $approve): RedirectResponse
    {
        $teacher = $this->teacher();
        $this->ensureOwn($teacher, $subject, $section, TeacherSession::settings($request));

        $approve->approve($teacher, $subject, Section::from($section), Period::Month1);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Marks approved. They are now locked.')]);

        return to_route('grades.teacher.section', ['subject' => $subject->uuid, 'section' => $section]);
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
