<?php

namespace App\Modules\Grades\Http;

use App\Modules\Grades\Domain\StudentAccess;
use App\Modules\Grades\Models\GradeSettings;
use App\Modules\Grades\Models\Student;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * A student's session (ADR-0034) holds only while the code it was opened
 * with is still the student's (a new code ends it), students may still
 * sign in (ADR-0037), and it has been used in
 * the last 30 minutes. Only the student guard is touched.
 */
class StudentSession
{
    public const CODE = 'grades.student.code';

    public const SEEN_AT = 'grades.student.seen_at';

    public const IDLE_MINUTES = 30;

    public function handle(Request $request, Closure $next): Response
    {
        $student = Auth::guard(Student::GUARD)->user();

        if (! $student instanceof Student) {
            return to_route('grades.student.login');
        }

        $seenAt = (int) $request->session()->get(self::SEEN_AT, 0);
        $reason = match (true) {
            $student->code_digest === null || ! hash_equals($student->code_digest, (string) $request->session()->get(self::CODE)) => __('Your code was changed. Sign in with the new code.'),
            $student->access_blocked || GradeSettings::current()->student_access === StudentAccess::Blocked => __('You can no longer sign in here. Ask the department.'),
            $seenAt < now()->subMinutes(self::IDLE_MINUTES)->getTimestamp() => __('You were signed out after 30 minutes without activity.'),
            default => null,
        };

        if ($reason !== null) {
            self::end($request);
            Inertia::flash('toast', ['type' => 'warning', 'message' => $reason]);

            return to_route('grades.student.login');
        }

        $request->session()->put(self::SEEN_AT, now()->getTimestamp());

        return $next($request);
    }

    public static function start(Request $request, Student $student): void
    {
        Auth::guard(Student::GUARD)->login($student);
        $request->session()->regenerate();
        $request->session()->put(self::CODE, (string) $student->code_digest);
        $request->session()->put(self::SEEN_AT, now()->getTimestamp());
    }

    public static function end(Request $request): void
    {
        Auth::guard(Student::GUARD)->logout();
        $request->session()->forget([self::CODE, self::SEEN_AT]);
        $request->session()->regenerateToken();
    }
}
