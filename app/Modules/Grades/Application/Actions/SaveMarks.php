<?php

namespace App\Modules\Grades\Application\Actions;

use App\Models\User;
use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Models\GradeMark;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditLogger;
use App\Platform\Exceptions\DomainRuleViolation;
use App\Platform\Outbox\Outbox;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Writes one section's marks in one subject (ADR-0034), in one transaction.
 *
 * - A teacher writes only their own subjects, only while active, and only
 *   while the period is open. The owner (grades.manage) may correct at any
 *   time.
 * - The subject's row is locked for update (saves of one subject wait for
 *   each other) and the period's row for share (closing waits for saves in
 *   progress, and a save after the close sees it closed).
 * - Only what changes is written; repeating a save changes nothing and
 *   records nothing.
 * - The audit record lists each change (from, to). A teacher is not a user,
 *   so a teacher's record has no actor id (actor ids are users) and names
 *   the teacher in its metadata.
 * - When the section becomes complete, the owner is told through the
 *   outbox, in the same transaction.
 */
class SaveMarks
{
    public const TOPIC = 'grades.section_completed';

    public function __construct(private readonly AuditLogger $audit, private readonly Outbox $outbox) {}

    /**
     * @param  array<string, Mark|null>  $marks  student UUID => mark (null: remove the mark)
     * @return array{changed: int, complete: bool}
     */
    public function handle(Teacher|User $author, Subject $subject, Section $section, Period $period, array $marks): array
    {
        GradePeriod::for($period);

        return DB::transaction(function () use ($author, $subject, $section, $period, $marks): array {
            $subject = Subject::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            $window = GradePeriod::query()->where('period', $period->value)->sharedLock()->firstOrFail();

            $this->authorise($author, $subject, $window->isOpen());

            if (! $subject->stage->hasSection($section)) {
                throw new DomainRuleViolation(__('This stage has no such section.'), 'marks');
            }

            $students = Student::query()
                ->where('stage', $subject->stage->value)
                ->where('section', $section->value)
                ->pluck('id', 'uuid')
                ->all();

            $unknown = array_diff(array_keys($marks), array_keys($students));
            if ($unknown !== []) {
                throw new DomainRuleViolation(__('Some students are not in this section.'), 'marks');
            }

            $existing = GradeMark::query()
                ->where('subject_id', $subject->id)
                ->where('stage', $subject->stage->value)
                ->where('period', $period->value)
                ->whereIn('student_id', array_values($students))
                ->get()
                ->keyBy('student_id');

            $completeBefore = $existing->count() === count($students);
            $changes = [];

            foreach ($marks as $uuid => $mark) {
                $studentId = $students[$uuid];
                $row = $existing->get($studentId);
                $old = $row?->value();

                if ($mark === null ? $row === null : $mark->equals($old)) {
                    continue;
                }

                $changes[$uuid] = ['from' => $old?->toScalar(), 'to' => $mark?->toScalar()];

                if ($mark === null) {
                    $row->delete();
                    $existing->forget($studentId);

                    continue;
                }

                $row ??= (new GradeMark)->forceFill([
                    'student_id' => $studentId,
                    'subject_id' => $subject->id,
                    'stage' => $subject->stage,
                    'period' => $period,
                ]);

                $row->forceFill([
                    'mark' => $mark->value,
                    'is_absent' => $mark->absent,
                    'entered_by_teacher_id' => $author instanceof Teacher ? $author->id : null,
                    'entered_by_user_id' => $author instanceof User ? $author->id : null,
                ])->save();
                $existing->put($studentId, $row);
            }

            $complete = count($students) > 0 && $existing->count() === count($students);

            if ($changes === []) {
                return ['changed' => 0, 'complete' => $complete];
            }

            $metadata = ['section' => $section->value, 'period' => $period->value, 'count' => count($changes)];
            if ($author instanceof Teacher) {
                $metadata += ['teacher' => $author->uuid, 'teacher_name' => $author->name];
                $author->forceFill(['last_seen_at' => now()])->saveQuietly();
            }

            $this->audit->record(
                'grades.marks_saved',
                $subject,
                $metadata,
                $changes,
                actor: $author instanceof User ? $author : null,
                // A teacher is not a user: never the user signed in on the same browser.
                withoutActor: $author instanceof Teacher,
            );

            if ($complete && ! $completeBefore) {
                $this->outbox->record(self::TOPIC, [
                    'subject' => $subject->uuid,
                    'section' => $section->value,
                    'period' => $period->value,
                ]);
            }

            return ['changed' => count($changes), 'complete' => $complete];
        }, 3);
    }

    private function authorise(Teacher|User $author, Subject $subject, bool $open): void
    {
        if ($author instanceof User) {
            EnsureManager::check($author);

            return;
        }

        $teacher = Teacher::query()->whereKey($author->id)->first();

        if ($teacher === null || ! $teacher->is_active || $subject->teacher_id !== $teacher->id) {
            throw new AuthorizationException;
        }

        if (! $open) {
            throw new DomainRuleViolation(__('Mark entry is closed for this period.'), 'marks');
        }
    }
}
