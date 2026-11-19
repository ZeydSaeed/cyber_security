<?php

namespace App\Modules\Grades\Application\Actions;

use App\Models\User;
use App\Modules\Grades\Application\SectionAccess;
use App\Modules\Grades\Domain\EditPolicy;
use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\TeacherAccess;
use App\Modules\Grades\Models\GradeMark;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\GradeSettings;
use App\Modules\Grades\Models\SectionApproval;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\SubjectTeacher;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditLogger;
use App\Platform\Exceptions\DomainRuleViolation;
use App\Platform\Outbox\Outbox;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Writes one section's marks in one subject (ADR-0034), in one transaction.
 *
 * - A teacher writes only the sections they teach, only while active, and
 *   as their section's access allows (ADR-0037: mode, editing time, grant;
 *   normally only while the period is open). The owner (grades.manage) may
 *   correct at any time.
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

            if (! $subject->stage->hasSection($section)) {
                throw new DomainRuleViolation(__('This stage has no such section.'), 'marks');
            }

            $policy = $this->authorise($author, $subject, $section, $window);

            $students = Student::query()
                ->where('stage', $subject->stage->value)
                ->where('section', $section->value)
                ->pluck('id', 'uuid')
                ->all();

            // The subject's own maximum (ADR-0039), read under its lock.
            foreach ($marks as $mark) {
                if ($mark !== null && ! $mark->absent && (int) $mark->value > $subject->max_mark) {
                    throw new DomainRuleViolation(__('Marks in this subject are out of :max.', ['max' => $subject->max_mark]), 'marks');
                }
            }

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
            $writes = [];
            $removals = [];
            $now = now();
            // Students with a mark after this save: student id => true.
            $held = array_fill_keys($existing->keys()->all(), true);
            $locked = 0;

            foreach ($marks as $uuid => $mark) {
                $studentId = $students[$uuid];
                $row = $existing->get($studentId);
                $old = $row?->value();

                if ($mark === null ? $row === null : $mark->equals($old)) {
                    continue;
                }

                // Timed mode (ADR-0037): a mark past its editing time can be
                // neither changed nor removed (removing it and entering it
                // again would restart its clock).
                if ($policy !== null && $row !== null && ! $policy->canChange($row->created_at)) {
                    $locked++;

                    continue;
                }

                $changes[$uuid] = ['from' => $old?->toScalar(), 'to' => $mark?->toScalar()];

                if ($mark === null) {
                    $removals[] = $row->id;
                    unset($held[$studentId]);

                    continue;
                }

                $writes[] = [
                    'student_id' => $studentId,
                    'subject_id' => $subject->id,
                    'stage' => $subject->stage->value,
                    'period' => $period->value,
                    'mark' => $mark->value,
                    'is_absent' => $mark->absent,
                    'entered_by_teacher_id' => $author instanceof Teacher ? $author->id : null,
                    'entered_by_user_id' => $author instanceof User ? $author->id : null,
                    // Kept on an existing row: created_at is not in the
                    // columns the upsert updates.
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $held[$studentId] = true;
            }

            if ($locked > 0) {
                throw new DomainRuleViolation($locked === 1
                    ? __('One mark can no longer be changed: its editing time is over. Ask the department to allow it.')
                    : __(':count marks can no longer be changed: their editing time is over. Ask the department to allow it.', ['count' => $locked]), 'marks');
            }

            // One statement for every write and one for every removal, not
            // one per student (review load test, 2026-11-18: a section of
            // 36 ran 36 statements while holding the subject's lock, which
            // its other section's teacher waits on). Safe under the lock:
            // nobody else writes this subject's marks meanwhile.
            if ($writes !== []) {
                GradeMark::query()->upsert(
                    $writes,
                    ['student_id', 'stage', 'subject_id', 'period'],
                    ['mark', 'is_absent', 'entered_by_teacher_id', 'entered_by_user_id', 'updated_at'],
                );
            }

            if ($removals !== []) {
                GradeMark::query()->whereKey($removals)->delete();
            }

            $complete = count($students) > 0 && count($held) === count($students);

            if ($changes === []) {
                return ['changed' => 0, 'complete' => $complete];
            }

            // A teacher changing approved marks (under a grant) must approve
            // them again (ADR-0041); the owner's corrections keep it.
            if ($author instanceof Teacher) {
                $cleared = SectionApproval::query()
                    ->where('subject_id', $subject->id)
                    ->where('section', $section->value)
                    ->where('period', $period->value)
                    ->delete();
                if ($cleared > 0) {
                    $this->audit->record('grades.section_approval_cleared', $subject, ['section' => $section->value, 'teacher_name' => $author->name], withoutActor: true);
                }
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

    /**
     * The owner may always correct (null: no limits). A teacher gets the
     * policy of their section (ADR-0037), read again inside the
     * transaction so a lock set a moment ago counts.
     */
    private function authorise(Teacher|User $author, Subject $subject, Section $section, GradePeriod $window): ?EditPolicy
    {
        if ($author instanceof User) {
            EnsureManager::check($author);

            return null;
        }

        $settings = GradeSettings::current();
        $teacher = Teacher::query()->whereKey($author->id)->first();
        $row = $teacher === null ? null : SubjectTeacher::query()
            ->where('subject_id', $subject->id)
            ->where('section', $section->value)
            ->where('teacher_id', $teacher->id)
            ->first();

        if ($teacher === null || $row === null || ! $teacher->is_active
            || $teacher->accessMode($settings) === TeacherAccess::Blocked
            || SectionAccess::hidden($teacher, $row, $settings)) {
            throw new AuthorizationException;
        }

        $approved = SectionApproval::query()
            ->where('subject_id', $subject->id)
            ->where('section', $section->value)
            ->where('period', $window->period->value)
            ->exists();
        $policy = SectionAccess::policy($teacher, $row, $settings, $window, $approved, $window->publishedFor($subject->stage, $section));

        if (! $policy->canWrite()) {
            throw new DomainRuleViolation(match (true) {
                $policy->published => __('The results are published; their marks can no longer be changed.'),
                ! $policy->windowOpen => __('Mark entry is closed for this period.'),
                $policy->approved => __('You approved these marks. Ask the department to change them.'),
                default => __('You can read these marks but not change them. Ask the department.'),
            }, 'marks');
        }

        return $policy;
    }
}
