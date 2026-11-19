<?php

namespace App\Modules\Grades\Http;

use App\Modules\Grades\Application\TeacherCodes;
use App\Modules\Grades\Domain\TeacherAccess;
use App\Modules\Grades\Models\GradeSettings;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Teachers sign in with their code (ADR-0034).
 *
 * - One answer for every refusal (unknown code, stopped teacher), so the
 *   page never tells which codes exist.
 * - Failed attempts are limited per address: by default 5 a minute and
 *   20 an hour; the owner sets both (ADR-0037).
 *   Only failures count, so teachers sharing a school's address do not lock
 *   each other out by signing in, and a success does not reset the count
 *   for someone guessing.
 * - Every success, failure and lockout is audited, never with the code, and
 *   with no actor (a teacher is not a user).
 */
class TeacherSessionController
{
    /** The defaults of grade_settings (ADR-0037). */
    public const PER_MINUTE = 5;

    public const PER_HOUR = 20;

    public function __construct(private readonly AuditLogger $audit) {}

    public function create(Request $request): Response|RedirectResponse
    {
        if (Auth::guard(Teacher::GUARD)->check()) {
            return to_route('grades.teacher.subjects');
        }

        return Inertia::render('modules/grades/pages/teacher/login');
    }

    public function store(Request $request, TeacherCodes $codes): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:'.TeacherCodes::MAX_LENGTH * 4]]);

        $settings = GradeSettings::current();

        // Every teacher stopped at once (ADR-0037): said before any code is
        // checked, so it tells nothing about codes.
        if ($settings->teacher_mode === TeacherAccess::Blocked) {
            throw ValidationException::withMessages(['code' => __('Signing in is closed for teachers for now.')]);
        }

        $keys = [
            'grades-teacher:minute:'.$request->ip() => [$settings->teacher_attempts_per_minute, 60],
            'grades-teacher:hour:'.$request->ip() => [$settings->teacher_attempts_per_hour, 3600],
        ];

        foreach ($keys as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $this->audit->record('grades.teacher_lockout', withoutActor: true);

                throw ValidationException::withMessages([
                    'code' => __('Too many attempts. Try again in :minutes minutes.', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)]),
                ]);
            }
        }

        $teacher = Teacher::query()->where('code_digest', $codes->digest((string) $request->string('code')))->first();

        // Unknown, stopped, blocked or past the cap on sign-ins: one answer.
        if ($teacher === null || ! $teacher->maySignIn($settings)) {
            foreach ($keys as $key => [, $decay]) {
                RateLimiter::hit($key, $decay);
            }

            $this->audit->record('grades.teacher_login_failed', $teacher, $teacher === null ? [] : ['refused' => true], withoutActor: true);

            throw ValidationException::withMessages(['code' => __('This code was not recognised.')]);
        }

        TeacherSession::start($request, $teacher);
        // Counted in the database: two sign-ins at once both count.
        Teacher::query()->whereKey($teacher->id)->increment('login_count', 1, ['last_seen_at' => now(), 'last_login_at' => now()]);
        $this->audit->record('grades.teacher_login', $teacher, ['teacher_name' => $teacher->name], withoutActor: true);

        return to_route('grades.teacher.subjects');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $teacher = Auth::guard(Teacher::GUARD)->user();

        if ($teacher instanceof Teacher) {
            $this->audit->record('grades.teacher_logout', $teacher, withoutActor: true);
        }

        TeacherSession::end($request);

        return to_route('grades.teacher.login');
    }
}
