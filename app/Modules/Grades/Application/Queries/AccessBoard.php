<?php

namespace App\Modules\Grades\Application\Queries;

use App\Modules\Grades\Domain\AppealStatus;
use App\Modules\Grades\Domain\EditRequestStatus;
use App\Modules\Grades\Models\GradeAppeal;
use App\Modules\Grades\Models\GradeEditRequest;
use App\Modules\Grades\Models\GradeSettings;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditEvent;

/**
 * The access part of the owner's overview (ADR-0037), as plain values: the
 * department's rules, each teacher with their sign-ins and the access of
 * each section they teach, the requests waiting for an answer, the
 * students' sign-ins, and the refused sign-ins of the last 24 hours. A
 * fixed number of queries whatever the number of teachers and students.
 */
final class AccessBoard
{
    /**
     * @return array<string, mixed>
     */
    public function show(): array
    {
        $settings = GradeSettings::current();
        $since = now()->subDay();

        $teachers = Teacher::query()
            ->withCount(['devices' => fn ($query) => $query->where('expires_at', '>', now())])
            ->with(['assignments' => fn ($query) => $query->with('subject:id,uuid,name,stage,sort_order')])
            ->orderBy('name')
            ->get();

        // Refused sign-ins of a known teacher (stopped, blocked, over the cap).
        $refused = AuditEvent::query()->toBase()
            ->where('action', 'grades.teacher_login_failed')
            ->where('occurred_at', '>=', $since)
            ->where('subject_type', Teacher::MORPH)
            ->selectRaw('subject_id, count(*) as n')
            ->groupBy('subject_id')
            ->pluck('n', 'subject_id');

        $failed = AuditEvent::query()->toBase()
            ->whereIn('action', ['grades.teacher_login_failed', 'grades.student_login_failed', 'grades.teacher_lockout', 'grades.student_lockout'])
            ->where('occurred_at', '>=', $since)
            ->selectRaw('action, count(*) as n')
            ->groupBy('action')
            ->pluck('n', 'action');

        $students = Student::query()->toBase()
            ->selectRaw('count(*) as total, count(*) filter (where login_count > 0) as signed_in, coalesce(sum(login_count), 0) as logins, count(*) filter (where access_blocked) as blocked')
            ->first();

        $requests = GradeEditRequest::query()
            ->with(['teacher:id,name', 'subject:id,name,stage'])
            ->where('status', EditRequestStatus::Pending->value)
            ->orderBy('created_at')
            ->get();

        // Students' appeals waiting for an answer (ADR-0042).
        $appeals = GradeAppeal::query()
            ->with(['student:id,full_name,stage,section', 'subject:id,uuid,name'])
            ->where('status', AppealStatus::Pending->value)
            ->orderBy('created_at')
            ->get();

        return [
            'appeals' => array_values($appeals->map(fn (GradeAppeal $appeal): array => [
                'uuid' => $appeal->uuid,
                'student' => $appeal->student?->full_name,
                'stage' => $appeal->student?->stage->value,
                'section' => $appeal->student?->section->value,
                'divided' => $appeal->student?->stage->isDivided() ?? false,
                'subject' => $appeal->subject?->name,
                'subject_uuid' => $appeal->subject?->uuid,
                'reason' => $appeal->reason,
                'created_at' => $appeal->created_at->toIso8601String(),
            ])->all()),
            'settings' => [
                'teacher_attempts_per_minute' => $settings->teacher_attempts_per_minute,
                'teacher_attempts_per_hour' => $settings->teacher_attempts_per_hour,
                'student_attempts_per_minute' => $settings->student_attempts_per_minute,
                'student_attempts_per_hour' => $settings->student_attempts_per_hour,
                'teacher_max_logins' => $settings->teacher_max_logins,
                'student_max_logins' => $settings->student_max_logins,
                'teacher_mode' => $settings->teacher_mode->value,
                'teacher_edit_minutes' => $settings->teacher_edit_minutes,
                'teacher_edit_until' => $settings->teacher_edit_until?->toIso8601String(),
                'student_access' => $settings->student_access->value,
                'remember_days' => $settings->remember_days,
            ],
            'teachers' => array_values($teachers->map(fn (Teacher $teacher): array => [
                'uuid' => $teacher->uuid,
                'name' => $teacher->name,
                'active' => $teacher->is_active,
                'mode' => $teacher->access_mode?->value,
                'effective_mode' => $teacher->accessMode($settings)->value,
                'edit_minutes' => $teacher->edit_minutes,
                'edit_until' => $teacher->edit_until?->toIso8601String(),
                'reason' => $teacher->access_reason,
                'logins' => $teacher->login_count,
                'last_login_at' => $teacher->last_login_at?->toIso8601String(),
                'refused' => (int) ($refused[(string) $teacher->id] ?? 0),
                // Remembered devices not expired (ADR-0038).
                'devices' => (int) $teacher->getAttribute('devices_count'),
                'sections' => $this->sections($teacher),
            ])->all()),
            'requests' => array_values($requests->map(fn (GradeEditRequest $request): array => [
                'uuid' => $request->uuid,
                'teacher' => $request->teacher?->name,
                'subject' => $request->subject?->name,
                'stage' => $request->subject?->stage->value,
                'divided' => $request->subject?->stage->isDivided() ?? false,
                'section' => $request->section->value,
                'reason' => $request->reason,
                'created_at' => $request->created_at->toIso8601String(),
            ])->all()),
            'students' => [
                'total' => (int) ($students->total ?? 0),
                'signed_in' => (int) ($students->signed_in ?? 0),
                'logins' => (int) ($students->logins ?? 0),
                'blocked' => (int) ($students->blocked ?? 0),
            ],
            'failed' => [
                'teachers' => (int) ($failed['grades.teacher_login_failed'] ?? 0),
                'students' => (int) ($failed['grades.student_login_failed'] ?? 0),
                'lockouts' => (int) (($failed['grades.teacher_lockout'] ?? 0) + ($failed['grades.student_lockout'] ?? 0)),
            ],
        ];
    }

    /**
     * The sections a teacher teaches, in stage and subject order, with
     * their own access.
     *
     * @return list<array<string, mixed>>
     */
    private function sections(Teacher $teacher): array
    {
        $sections = [];

        foreach ($teacher->assignments as $row) {
            $subject = $row->subject;
            if ($subject === null) {
                continue;
            }

            $order = sprintf('%02d|%05d|%s|%s', $subject->stage->value, $subject->sort_order, $subject->name, $row->section->value);
            $sections[$order] = [
                'subject' => $subject->uuid,
                'name' => $subject->name,
                'stage' => $subject->stage->value,
                'divided' => $subject->stage->isDivided(),
                'section' => $row->section->value,
                'mode' => $row->access_mode?->value,
                'reason' => $row->access_reason,
                'edit_until' => $row->edit_until !== null && $row->edit_until->isFuture() ? $row->edit_until->toIso8601String() : null,
            ];
        }

        ksort($sections);

        return array_values($sections);
    }
}
