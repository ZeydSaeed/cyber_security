<?php

namespace App\Modules\Grades\Application\Actions;

use App\Modules\Grades\Application\Jobs\NotifyGradesManagers;
use App\Modules\Grades\Application\Notifications\GradesNotification;
use App\Modules\Grades\Application\SectionAccess;
use App\Modules\Grades\Domain\EditRequestStatus;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Models\GradeEditRequest;
use App\Modules\Grades\Models\GradeSettings;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\SubjectTeacher;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditLogger;
use App\Platform\Exceptions\DomainRuleViolation;
use App\Platform\Outbox\Outbox;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * A teacher asks the owner to let them change a section's marks again
 * (ADR-0037): after mark entry closed, in a view-only mode, or once the
 * editing time of some marks is over. One open request per section; the
 * owner approves it for some hours or rejects it.
 */
class RequestEdit
{
    public const MIN_REASON = 5;

    public const MAX_REASON = 500;

    public function __construct(private readonly AuditLogger $audit, private readonly Outbox $outbox) {}

    public function handle(Teacher $teacher, Subject $subject, Section $section, Period $period, string $reason): GradeEditRequest
    {
        $reason = trim($reason);
        $length = mb_strlen($reason);
        if ($length < self::MIN_REASON || $length > self::MAX_REASON) {
            throw new DomainRuleViolation(__('Say why in :min to :max characters.', ['min' => self::MIN_REASON, 'max' => self::MAX_REASON]), 'reason');
        }

        return DB::transaction(function () use ($teacher, $subject, $section, $period, $reason): GradeEditRequest {
            $row = SubjectTeacher::query()
                ->where('subject_id', $subject->id)
                ->where('section', $section->value)
                ->where('teacher_id', $teacher->id)
                ->lockForUpdate()
                ->first();
            $fresh = Teacher::query()->whereKey($teacher->id)->first();

            if ($row === null || $fresh === null || ! $fresh->is_active || SectionAccess::hidden($fresh, $row, GradeSettings::current())) {
                throw new AuthorizationException;
            }

            $pending = GradeEditRequest::query()
                ->where('teacher_id', $teacher->id)
                ->where('subject_id', $subject->id)
                ->where('section', $section->value)
                ->where('period', $period->value)
                ->where('status', EditRequestStatus::Pending->value)
                ->exists();

            if ($pending) {
                throw new DomainRuleViolation(__('Your request for this section is still waiting for an answer.'), 'reason');
            }

            $request = new GradeEditRequest;
            $request->forceFill([
                'teacher_id' => $teacher->id,
                'subject_id' => $subject->id,
                'section' => $section,
                'period' => $period,
                'reason' => $reason,
                'status' => EditRequestStatus::Pending,
            ])->save();

            // A teacher is not a user: no actor, the teacher named in the metadata.
            $this->audit->record('grades.edit_requested', $request, [
                'teacher' => $teacher->uuid,
                'teacher_name' => $teacher->name,
                'section' => $section->value,
            ], withoutActor: true);

            // The department hears of it (ADR-0042).
            $this->outbox->record(NotifyGradesManagers::TOPIC, [
                'kind' => GradesNotification::EDIT_REQUESTED,
                'facts' => ['teacher' => $teacher->name, 'subject' => $subject->name, 'stage' => (string) $subject->stage->value, 'section' => $section->value],
            ]);

            return $request;
        }, 3);
    }
}
