<?php

namespace App\Modules\Grades\Http;

use App\Models\User;
use App\Modules\Grades\Application\Actions\ManageAppeals;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Models\GradeAppeal;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Appeals of marks (ADR-0042): a student sends one from their result
 * page; the owner answers it on the overview.
 */
class AppealsController
{
    public function __construct(private readonly ManageAppeals $appeals) {}

    public function store(Request $request): RedirectResponse
    {
        $student = Auth::guard(Student::GUARD)->user();
        if (! $student instanceof Student) {
            throw new NotFoundHttpException;
        }

        $request->validate([
            'subject' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:'.ManageAppeals::MAX_TEXT * 2],
        ]);
        $subject = Subject::query()->where('uuid', (string) $request->string('subject'))->first()
            ?? throw new NotFoundHttpException;

        $this->appeals->submit($student, $subject, Period::Month1, (string) $request->string('reason'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Appeal sent. The department will answer it.')]);

        return to_route('grades.student.result');
    }

    public function decide(Request $request, GradeAppeal $appeal, #[CurrentUser] User $user): RedirectResponse
    {
        $request->validate([
            'decision' => ['required', Rule::in(['accept', 'reject'])],
            'response' => ['nullable', 'string', 'max:'.ManageAppeals::MAX_TEXT],
        ]);
        $accept = $request->string('decision')->toString() === 'accept';

        $this->appeals->decide($user, $appeal, $accept, $request->filled('response') ? (string) $request->string('response') : null);
        Inertia::flash('toast', ['type' => 'success', 'message' => $accept
            ? __('Appeal accepted. Correct the mark on the subject\'s sheet if it changes.')
            : __('Appeal rejected.')]);

        return back();
    }
}
