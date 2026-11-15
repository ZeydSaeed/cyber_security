<?php

namespace App\Modules\Grades\Http;

use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Application\Queries\TeacherSheets;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A teacher's pages (ADR-0034): their subjects, and one section's sheet
 * to fill in. Another teacher's subject is a 404, not a 403, so subject
 * addresses cannot be probed.
 */
class TeacherMarksController
{
    public function __construct(private readonly TeacherSheets $sheets) {}

    public function subjects(): Response
    {
        $teacher = $this->teacher();

        return Inertia::render('modules/grades/pages/teacher/subjects', [
            'portal' => ['name' => $teacher->name, 'logout' => route('grades.teacher.logout')],
            ...$this->sheets->subjects($teacher),
        ]);
    }

    public function section(Subject $subject, string $section): Response
    {
        $teacher = $this->teacher();
        $this->ensureOwn($teacher, $subject, $section);

        return Inertia::render('modules/grades/pages/teacher/section', [
            'portal' => ['name' => $teacher->name, 'logout' => route('grades.teacher.logout')],
            ...$this->sheets->section($subject, Section::from($section)),
        ]);
    }

    public function save(SaveMarksRequest $request, Subject $subject, string $section, SaveMarks $save): RedirectResponse
    {
        $teacher = $this->teacher();
        $this->ensureOwn($teacher, $subject, $section);

        $result = $save->handle($teacher, $subject, Section::from($section), Period::Month1, $request->marks());

        $message = match (true) {
            $result['changed'] === 0 => __('Nothing changed.'),
            $result['complete'] => __('Saved. Every student in this section has a mark.'),
            default => __('Saved.'),
        };
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

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

    private function ensureOwn(Teacher $teacher, Subject $subject, ?string $section = null): void
    {
        // Another teacher's subject, or a section the stage does not have.
        if ($subject->teacher_id !== $teacher->id || ($section !== null && ! $subject->stage->hasSection(Section::from($section)))) {
            throw new NotFoundHttpException;
        }
    }
}
