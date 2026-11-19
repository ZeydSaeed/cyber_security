<?php

namespace App\Modules\Grades\Application\Actions;

use App\Models\User;
use App\Modules\Grades\Application\Jobs\NotifyGradesManagers;
use App\Modules\Grades\Application\Notifications\GradesNotification;
use App\Modules\Grades\Domain\AppealScope;
use App\Modules\Grades\Domain\AppealStatus;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Models\GradeAppeal;
use App\Modules\Grades\Models\GradeMark;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\GradeSettings;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\SubjectTeacher;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditLogger;
use App\Platform\Exceptions\DomainRuleViolation;
use App\Platform\Outbox\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Students appeal a mark (ADR-0042): only once their results are
 * published, only within the days the owner allows after that, only a
 * subject of their stage with a mark, one waiting appeal per subject. The
 * owner (and the subject's teacher, on their sheet) sees it; the owner
 * accepts or rejects it with an answer the student reads. Accepting does
 * not change the mark: the owner corrects it on the sheet, recorded there.
 */
class ManageAppeals
{
    public const MIN_REASON = 5;

    public const MAX_TEXT = 500;

    public function __construct(private readonly AuditLogger $audit, private readonly Outbox $outbox) {}

    /**
     * Until when this student may appeal (null: not now). Appeals need the
     * appeal box shown, the student's section published, and the student
     * in reach (every student, or one the owner allowed). They close after
     * the given minutes from publication or at the closing date, whichever
     * comes first (ADR-0043).
     */
    public static function openUntil(Student $student, GradeSettings $settings, GradePeriod $window): ?CarbonImmutable
    {
        if (! $settings->options()['appeals']) {
            return null;
        }
        if ($settings->appeal_scope === AppealScope::Selected && ! $student->appeal_allowed) {
            return null;
        }

        $published = $window->publishedAtFor($student->stage, $student->section);
        $until = $published === null ? null : self::closesAt($settings, $published);

        return $until !== null && $until->isFuture() ? $until : null;
    }

    /**
     * When appeals close for results published at the given moment: the
     * given minutes after it or the closing date, whichever comes first
     * (null: appeals are off). Also used by the reminders (ADR-0045).
     */
    public static function closesAt(GradeSettings $settings, CarbonImmutable $published): ?CarbonImmutable
    {
        if (! $settings->options()['appeals']) {
            return null;
        }

        $byTime = $settings->appeal_minutes === null ? null : $published->addMinutes($settings->appeal_minutes);
        $byDate = $settings->appeal_until;

        return match (true) {
            $byTime === null => $byDate,
            $byDate === null => $byTime,
            default => $byTime->min($byDate),
        };
    }

    public function submit(Student $student, Subject $subject, Period $period, string $reason): GradeAppeal
    {
        $reason = trim($reason);
        $length = mb_strlen($reason);
        if ($length < self::MIN_REASON || $length > self::MAX_TEXT) {
            throw new DomainRuleViolation(__('Say why in :min to :max characters.', ['min' => self::MIN_REASON, 'max' => self::MAX_TEXT]), 'reason');
        }

        return DB::transaction(function () use ($student, $subject, $period, $reason): GradeAppeal {
            $fresh = Student::query()->whereKey($student->id)->lockForUpdate()->firstOrFail();

            if (self::openUntil($fresh, GradeSettings::current(), GradePeriod::for($period)) === null) {
                throw new DomainRuleViolation(__('Appeals are closed.'), 'reason');
            }

            $hasMark = GradeMark::query()
                ->where('student_id', $fresh->id)
                ->where('subject_id', $subject->id)
                ->where('period', $period->value)
                ->exists();
            if ($subject->stage !== $fresh->stage || ! $hasMark) {
                throw new DomainRuleViolation(__('Choose a subject of yours that has a mark.'), 'subject');
            }

            $waiting = GradeAppeal::query()
                ->where('student_id', $fresh->id)
                ->where('subject_id', $subject->id)
                ->where('period', $period->value)
                ->where('status', AppealStatus::Pending->value)
                ->exists();
            if ($waiting) {
                throw new DomainRuleViolation(__('Your appeal of this subject is still waiting for an answer.'), 'subject');
            }

            $appeal = new GradeAppeal;
            $appeal->forceFill([
                'student_id' => $fresh->id,
                'subject_id' => $subject->id,
                'period' => $period,
                'reason' => $reason,
                'status' => AppealStatus::Pending,
            ])->save();

            // A student is not a user: no actor.
            $this->audit->record('grades.appeal_submitted', $appeal, ['subject' => $subject->name], withoutActor: true);
            $this->outbox->record(NotifyGradesManagers::TOPIC, [
                'kind' => GradesNotification::APPEAL_SUBMITTED,
                'facts' => ['student' => $fresh->full_name, 'subject' => $subject->name],
            ]);

            return $appeal;
        }, 3);
    }

    public function decide(User $actor, GradeAppeal $appeal, bool $accept, ?string $response): GradeAppeal
    {
        $response = $this->validResponse($response);

        return DB::transaction(function () use ($actor, $appeal, $accept, $response): GradeAppeal {
            $locked = GradeAppeal::query()->whereKey($appeal->id)->lockForUpdate()->firstOrFail();
            EnsureManager::check($actor);

            $next = $accept ? AppealStatus::Accepted : AppealStatus::Rejected;
            if (! $locked->status->canBecome($next)) {
                throw new DomainRuleViolation(__('This appeal has already been answered.'), 'appeal');
            }

            $locked->forceFill(['status' => $next, 'response' => $response, 'decided_by_user_id' => $actor->id, 'decided_at' => now()])->save();
            $this->audit->record('grades.appeal_decided', $locked, ['decision' => $next->value], actor: $actor);

            return $locked;
        }, 3);
    }

    /**
     * The subject's teacher answers an appeal (ADR-0044): only when the
     * owner allows teachers to, only for a section they teach, once. The
     * department is told, and can still read every answer.
     */
    public function answerAsTeacher(Teacher $teacher, GradeAppeal $appeal, bool $accept, ?string $response): GradeAppeal
    {
        $response = $this->validResponse($response);

        return DB::transaction(function () use ($teacher, $appeal, $accept, $response): GradeAppeal {
            $locked = GradeAppeal::query()->whereKey($appeal->id)->lockForUpdate()->firstOrFail();

            if (! GradeSettings::current()->teacher_appeal_replies) {
                throw new DomainRuleViolation(__('The department answers the appeals.'), 'appeal');
            }

            $student = Student::query()->whereKey($locked->student_id)->first(['id', 'full_name', 'section']);
            $teaches = $student !== null && SubjectTeacher::query()
                ->where('subject_id', $locked->subject_id)
                ->where('section', $student->section->value)
                ->where('teacher_id', $teacher->id)
                ->exists();
            if (! $teaches) {
                throw new DomainRuleViolation(__('This appeal is not of a section you teach.'), 'appeal');
            }

            $next = $accept ? AppealStatus::Accepted : AppealStatus::Rejected;
            if (! $locked->status->canBecome($next)) {
                throw new DomainRuleViolation(__('This appeal has already been answered.'), 'appeal');
            }

            $locked->forceFill(['status' => $next, 'response' => $response, 'decided_by_teacher_id' => $teacher->id, 'decided_at' => now()])->save();
            // A teacher is not a user: no actor; the teacher is named.
            $this->audit->record('grades.appeal_decided', $locked, ['decision' => $next->value, 'teacher_name' => $teacher->name], withoutActor: true);
            $this->outbox->record(NotifyGradesManagers::TOPIC, [
                'kind' => GradesNotification::APPEAL_ANSWERED,
                'facts' => [
                    'teacher' => $teacher->name,
                    'student' => $student->full_name,
                    'subject' => (string) Subject::query()->whereKey($locked->subject_id)->value('name'),
                    'decision' => $next->value,
                ],
            ]);

            return $locked;
        }, 3);
    }

    private function validResponse(?string $response): ?string
    {
        $response = $response === null ? null : trim($response);
        $response = $response === '' ? null : $response;
        if ($response !== null && mb_strlen($response) > self::MAX_TEXT) {
            throw new DomainRuleViolation(__('The answer is too long.'), 'response');
        }

        return $response;
    }
}
