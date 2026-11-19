<?php

namespace App\Modules\Grades\Http;

use App\Modules\Grades\Application\Queries\ResultSheets;
use App\Modules\Grades\Application\RememberedDevices;
use App\Modules\Grades\Application\StudentCodes;
use App\Modules\Grades\Domain\DeviceHolder;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\StudentAccess;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\GradeSettings;
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
 * answer for every refusal, failed attempts limited per address (by
 * default 5 a minute, 20 an hour; the owner sets both, ADR-0037), every sign-in audited with no actor and never the
 * code.
 */
class StudentPortalController
{
    public const PER_MINUTE = 5;

    public const PER_HOUR = 20;

    public function __construct(private readonly AuditLogger $audit, private readonly RememberedDevices $devices) {}

    /**
     * The code page; a remembered device (ADR-0038) goes straight in.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        if (Auth::guard(Student::GUARD)->check()) {
            return to_route('grades.student.result');
        }

        $settings = GradeSettings::current();
        $token = DeviceCookie::read($request, DeviceHolder::Student);

        if ($token !== null) {
            $found = $settings->student_access === StudentAccess::Allowed
                ? $this->devices->consume(DeviceHolder::Student, $token, $settings)
                : null;

            if ($found !== null && $found[0] instanceof Student) {
                [$student, $next] = $found;
                DeviceCookie::put(DeviceHolder::Student, $next, $settings->remember_days);
                $this->signIn($request, $student, remembered: true);

                return to_route('grades.student.result');
            }

            DeviceCookie::forget(DeviceHolder::Student);
        }

        return Inertia::render('modules/grades/pages/student/login', [
            // 0: the option is off.
            'rememberDays' => $settings->remember_days,
        ]);
    }

    public function store(Request $request, StudentCodes $codes): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $settings = GradeSettings::current();

        // Every student stopped at once (ADR-0037): said before any code is
        // checked, so it tells nothing about codes.
        if ($settings->student_access === StudentAccess::Blocked) {
            throw ValidationException::withMessages(['code' => __('Signing in is closed for students for now.')]);
        }

        $keys = [
            'grades-student:minute:'.$request->ip() => [$settings->student_attempts_per_minute, 60],
            'grades-student:hour:'.$request->ip() => [$settings->student_attempts_per_hour, 3600],
        ];

        foreach ($keys as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $this->audit->record('grades.student_lockout', withoutActor: true);

                throw ValidationException::withMessages([
                    'code' => __('Too many attempts. Try again in :minutes minutes.', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)]),
                ]);
            }
        }

        $student = Student::query()->where('code_digest', $codes->digest((string) $request->string('code')))->first();

        // Unknown, stopped or past the cap on sign-ins: one answer.
        if ($student === null || ! $student->maySignIn($settings)) {
            foreach ($keys as $key => [, $decay]) {
                RateLimiter::hit($key, $decay);
            }

            $this->audit->record('grades.student_login_failed', $student, $student === null ? [] : ['refused' => true], withoutActor: true);

            throw ValidationException::withMessages(['code' => __('This code was not recognised.')]);
        }

        if ($request->boolean('remember') && $settings->remember_days > 0) {
            DeviceCookie::put(DeviceHolder::Student, $this->devices->remember($student, $settings->remember_days, $request->userAgent()), $settings->remember_days);
        }

        $this->signIn($request, $student, remembered: false);

        return to_route('grades.student.result');
    }

    private function signIn(Request $request, Student $student, bool $remembered): void
    {
        StudentSession::start($request, $student);
        // Counted in the database: two sign-ins at once both count.
        Student::query()->whereKey($student->id)->increment('login_count', 1, ['last_login_at' => now()]);
        $this->audit->record('grades.student_login', $student, $remembered ? ['remembered' => true] : [], withoutActor: true);
    }

    public function destroy(Request $request): RedirectResponse
    {
        // Signing out also forgets this device (ADR-0038).
        $token = DeviceCookie::read($request, DeviceHolder::Student);
        if ($token !== null) {
            $this->devices->forget(DeviceHolder::Student, $token);
            DeviceCookie::forget(DeviceHolder::Student);
        }

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
            // Only what the owner chose to show (ADR-0039).
            'card' => $published ? $sheets->forStudent($student) : null,
        ]);
    }
}
