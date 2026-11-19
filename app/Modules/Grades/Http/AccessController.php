<?php

namespace App\Modules\Grades\Http;

use App\Models\User;
use App\Modules\Grades\Application\Actions\ManageAccess;
use App\Modules\Grades\Application\RememberedDevices;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\StudentAccess;
use App\Modules\Grades\Domain\TeacherAccess;
use App\Modules\Grades\Models\GradeEditRequest;
use App\Modules\Grades\Models\GradeSettings;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * The owner's control of sign-ins and mark editing (ADR-0037), on the
 * overview of the first month. The routes check grades.manage; each action
 * checks it again inside its transaction.
 */
class AccessController
{
    public function __construct(private readonly ManageAccess $access) {}

    public function settings(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        // The teachers' and the students' rules have their own tabs
        // (2026-11-19): a request carries one side or both; the side it
        // does not carry keeps its saved values.
        $teachers = $request->has('teacher_mode');
        $students = $request->has('student_access');
        $attempts = ['required', 'integer', 'min:1'];
        $cap = ['nullable', 'integer', 'min:1', 'max:100000'];
        $request->validate([
            ...($teachers ? [
                'teacher_attempts_per_minute' => [...$attempts, 'max:100'],
                'teacher_attempts_per_hour' => [...$attempts, 'max:1000', 'gte:teacher_attempts_per_minute'],
                'teacher_max_logins' => $cap,
                'teacher_mode' => ['required', Rule::enum(TeacherAccess::class)],
                // The editing time (ADR-0043): days, hours and minutes, and/or a deadline.
                ...DurationInput::rules('teacher_edit'),
                'teacher_edit_until' => ['nullable', 'date'],
                // Days a device stays remembered (ADR-0038); 0 turns it off.
                'remember_days' => ['required', 'integer', 'min:0', 'max:365'],
            ] : []),
            ...($students ? [
                'student_attempts_per_minute' => [...$attempts, 'max:100'],
                'student_attempts_per_hour' => [...$attempts, 'max:1000', 'gte:student_attempts_per_minute'],
                'student_max_logins' => $cap,
                'student_access' => ['required', Rule::enum(StudentAccess::class)],
            ] : []),
            // At least one side.
            'teacher_mode' => [Rule::requiredIf(! $students), Rule::enum(TeacherAccess::class)],
        ]);

        $saved = GradeSettings::current();
        $this->access->settings($user, [
            'teacher_attempts_per_minute' => $teachers ? $request->integer('teacher_attempts_per_minute') : $saved->teacher_attempts_per_minute,
            'teacher_attempts_per_hour' => $teachers ? $request->integer('teacher_attempts_per_hour') : $saved->teacher_attempts_per_hour,
            'student_attempts_per_minute' => $students ? $request->integer('student_attempts_per_minute') : $saved->student_attempts_per_minute,
            'student_attempts_per_hour' => $students ? $request->integer('student_attempts_per_hour') : $saved->student_attempts_per_hour,
            'teacher_max_logins' => $teachers ? $this->optional($request, 'teacher_max_logins') : $saved->teacher_max_logins,
            'student_max_logins' => $students ? $this->optional($request, 'student_max_logins') : $saved->student_max_logins,
            'teacher_mode' => $teachers ? TeacherAccess::from((string) $request->string('teacher_mode')) : $saved->teacher_mode,
            'teacher_edit_minutes' => $teachers ? DurationInput::minutes($request, 'teacher_edit') : $saved->teacher_edit_minutes,
            'teacher_edit_until' => $teachers ? DurationInput::moment($request, 'teacher_edit_until') : $saved->teacher_edit_until,
            'student_access' => $students ? StudentAccess::from((string) $request->string('student_access')) : $saved->student_access,
            'remember_days' => $teachers ? $request->integer('remember_days') : $saved->remember_days,
        ]);

        return $this->done(__('Access settings saved.'));
    }

    public function teacher(Request $request, Teacher $teacher, #[CurrentUser] User $user): RedirectResponse
    {
        $request->validate([
            'mode' => ['nullable', Rule::enum(TeacherAccess::class)],
            ...DurationInput::rules('edit'),
            'edit_until' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->access->teacher(
            $user,
            $teacher,
            $request->filled('mode') ? TeacherAccess::from((string) $request->string('mode')) : null,
            DurationInput::minutes($request, 'edit'),
            $request->filled('reason') ? (string) $request->string('reason') : null,
            DurationInput::moment($request, 'edit_until'),
        );

        return $this->done(__('The teacher\'s access was saved.'));
    }

    public function section(Request $request, Subject $subject, string $section, #[CurrentUser] User $user): RedirectResponse
    {
        $request->validate([
            'mode' => ['nullable', Rule::enum(TeacherAccess::class)],
            'reason' => ['nullable', 'string', 'max:255'],
            // Hours of editing from now; 0 withdraws a grant; absent keeps it.
            'grant_hours' => ['nullable', 'integer', 'min:0', 'max:'.ManageAccess::MAX_HOURS],
        ]);

        $this->access->section(
            $user,
            $subject,
            Section::from($section),
            $request->filled('mode') ? TeacherAccess::from((string) $request->string('mode')) : null,
            $request->filled('reason') ? (string) $request->string('reason') : null,
            $this->optional($request, 'grant_hours'),
        );

        return $this->done(__('The section\'s access was saved.'));
    }

    public function resetTeacherLogins(Teacher $teacher, #[CurrentUser] User $user): RedirectResponse
    {
        $this->access->resetTeacherLogins($user, $teacher);

        return $this->done(__('The teacher\'s sign-ins were counted from zero again.'));
    }

    public function resetStudentLogins(#[CurrentUser] User $user): RedirectResponse
    {
        $this->access->resetStudentLogins($user);

        return $this->done(__('The students\' sign-ins were counted from zero again.'));
    }

    public function student(Request $request, Student $student, #[CurrentUser] User $user): RedirectResponse
    {
        $request->validate(['blocked' => ['required', 'boolean']]);
        $blocked = $request->boolean('blocked');

        $this->access->student($user, $student, $blocked);

        return $this->done($blocked ? __('The student can no longer sign in.') : __('The student can sign in again.'));
    }

    /** Whether one student may appeal when appeals are for chosen students (ADR-0043). */
    public function studentAppeal(Request $request, Student $student, #[CurrentUser] User $user): RedirectResponse
    {
        $request->validate(['allowed' => ['required', 'boolean']]);
        $allowed = $request->boolean('allowed');

        $this->access->studentAppeal($user, $student, $allowed);

        return $this->done($allowed ? __('The student may appeal.') : __('The student may no longer appeal.'));
    }

    public function decide(Request $request, GradeEditRequest $editRequest, #[CurrentUser] User $user): RedirectResponse
    {
        $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'hours' => ['required_if:decision,approve', 'nullable', 'integer', 'min:1', 'max:'.ManageAccess::MAX_HOURS],
        ]);
        $approve = $request->string('decision')->toString() === 'approve';

        $this->access->decide($user, $editRequest, $approve, $approve ? $request->integer('hours') : null);

        return $this->done($approve ? __('Request approved. The teacher can change the marks now.') : __('Request rejected.'));
    }

    /** Forgets every remembered device of a teacher (ADR-0038). */
    public function forgetTeacherDevices(Teacher $teacher, RememberedDevices $devices, #[CurrentUser] User $user): RedirectResponse
    {
        $devices->forgetAll($user, $teacher);

        return $this->done(__('The remembered devices were forgotten. A new sign-in needs the code.'));
    }

    /** Forgets every remembered device of a student (ADR-0038). */
    public function forgetStudentDevices(Student $student, RememberedDevices $devices, #[CurrentUser] User $user): RedirectResponse
    {
        $devices->forgetAll($user, $student);

        return $this->done(__('The remembered devices were forgotten. A new sign-in needs the code.'));
    }

    private function optional(Request $request, string $field): ?int
    {
        return $request->filled($field) ? $request->integer($field) : null;
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
