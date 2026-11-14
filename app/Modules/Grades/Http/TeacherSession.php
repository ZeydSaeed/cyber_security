<?php

namespace App\Modules\Grades\Http;

use App\Modules\Grades\Models\Teacher;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * A teacher's session (ADR-0034) holds only while:
 *
 * - the teacher is still allowed to sign in,
 * - the code has not changed since sign-in (the owner changing a code or
 *   stopping a teacher raises the lock version, which ends every session
 *   opened before),
 * - and it has been used in the last 30 minutes.
 *
 * Otherwise the teacher is signed out and sent to the code page with the
 * reason. Only the teacher guard is touched: a user signed in on the same
 * browser stays signed in.
 */
class TeacherSession
{
    public const VERSION = 'grades.teacher.version';

    public const SEEN_AT = 'grades.teacher.seen_at';

    public const IDLE_MINUTES = 30;

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard(Teacher::GUARD);
        $teacher = $guard->user();

        if (! $teacher instanceof Teacher) {
            return to_route('grades.teacher.login');
        }

        $seenAt = (int) $request->session()->get(self::SEEN_AT, 0);
        $reason = match (true) {
            ! $teacher->is_active => __('You can no longer sign in here. Ask the department.'),
            $request->session()->get(self::VERSION) !== $teacher->lock_version => __('Your code was changed. Sign in with the new code.'),
            $seenAt < now()->subMinutes(self::IDLE_MINUTES)->getTimestamp() => __('You were signed out after 30 minutes without activity.'),
            default => null,
        };

        if ($reason !== null) {
            self::end($request);
            Inertia::flash('toast', ['type' => 'warning', 'message' => $reason]);

            return to_route('grades.teacher.login');
        }

        $request->session()->put(self::SEEN_AT, now()->getTimestamp());

        return $next($request);
    }

    public static function start(Request $request, Teacher $teacher): void
    {
        Auth::guard(Teacher::GUARD)->login($teacher);
        $request->session()->regenerate();
        $request->session()->put(self::VERSION, $teacher->lock_version);
        $request->session()->put(self::SEEN_AT, now()->getTimestamp());
    }

    public static function end(Request $request): void
    {
        Auth::guard(Teacher::GUARD)->logout();
        $request->session()->forget([self::VERSION, self::SEEN_AT]);
        $request->session()->regenerateToken();
    }
}
