<?php

namespace App\Modules\Grades\Http;

use App\Modules\Grades\Application\Queries\ResultSheets;
use App\Modules\Grades\Application\StudentCodes;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\Student;
use App\Platform\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Students see their own result (ADR-0034, owner's decision of
 * 2026-11-15): /student takes the code the application gave them; the
 * result shows only once the owner has published it. As for teachers: one
 * answer for every refusal, failed attempts limited per address (5 a
 * minute, 20 an hour), every sign-in audited with no actor and never the
 * code.
 */
class StudentPortalController
{
    public const PER_MINUTE = 5;

    public const PER_HOUR = 20;

    public function __construct(private readonly AuditLogger $audit) {}

    public function create(): Response|RedirectResponse
    {
        if (Auth::guard(Student::GUARD)->check()) {
            return to_route('grades.student.result');
        }

        return Inertia::render('modules/grades/pages/student/login');
    }

    public function store(Request $request, StudentCodes $codes): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:64']]);

        $keys = ['grades-student:minute:'.$request->ip() => [self::PER_MINUTE, 60], 'grades-student:hour:'.$request->ip() => [self::PER_HOUR, 3600]];

        foreach ($keys as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $this->audit->record('grades.student_lockout', withoutActor: true);

                throw ValidationException::withMessages([
                    'code' => __('Too many attempts. Try again in :minutes minutes.', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)]),
                ]);
            }
        }

        $student = Student::query()->where('code_digest', $codes->digest((string) $request->string('code')))->first();

        if ($student === null) {
            foreach ($keys as $key => [, $decay]) {
                RateLimiter::hit($key, $decay);
            }

            $this->audit->record('grades.student_login_failed', withoutActor: true);

            throw ValidationException::withMessages(['code' => __('This code was not recognised.')]);
        }

        StudentSession::start($request, $student);
        $this->audit->record('grades.student_login', $student, withoutActor: true);

        return to_route('grades.student.result');
    }

    public function destroy(Request $request): RedirectResponse
    {
        StudentSession::end($request);

        return to_route('grades.student.login');
    }

    public function result(ResultSheets $sheets): Response
    {
        $student = Auth::guard(Student::GUARD)->user();

        if (! $student instanceof Student) {
            throw new NotFoundHttpException;
        }

        $published = GradePeriod::for(Period::Month1)->isPublished();

        return Inertia::render('modules/grades/pages/student/result', [
            'portal' => ['name' => $student->full_name, 'logout' => route('grades.student.logout')],
            'published' => $published,
            // Nothing of the marks before the owner publishes them.
            'card' => $published ? $sheets->student($student) : null,
        ]);
    }
}
