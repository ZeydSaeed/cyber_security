<?php

namespace App\Modules\Grades\Application;

use App\Models\User;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditEvent;
use App\Platform\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Who saw a student's result, and when (ADR-0045): the student on their
 * page, a user on the owner's card, and the section's teachers on their
 * results page (already recorded per section, ADR-0040). Kept in the
 * audit trail; a page read again within ten minutes is not recorded again.
 */
final class ResultViews
{
    /** A repeated view within this many minutes is the same visit. */
    public const QUIET_MINUTES = 10;

    /** How many views the owner's page lists. */
    public const LIMIT = 100;

    public function __construct(private readonly AuditLogger $audit) {}

    /** The student read their own published result. */
    public function byStudent(Student $student): void
    {
        if (Cache::add("grades:result-viewed:student:{$student->id}", true, now()->addMinutes(self::QUIET_MINUTES))) {
            // A student is not a user: no actor.
            $this->audit->record('grades.student_result_viewed', $student, withoutActor: true);
        }
    }

    /** A user opened the student's card. */
    public function byUser(Student $student, User $user): void
    {
        if (Cache::add("grades:result-viewed:user:{$user->id}:{$student->id}", true, now()->addMinutes(self::QUIET_MINUTES))) {
            $this->audit->record('grades.student_card_viewed', $student, actor: $user);
        }
    }

    /**
     * The newest views of a student's result. Teachers' views are of the
     * student's section. Four queries.
     *
     * @return list<array{at: string, who: string, kind: string}>
     */
    public function of(Student $student): array
    {
        $own = AuditEvent::query()
            ->where('subject_type', Student::MORPH)
            ->where('subject_id', (string) $student->id)
            ->whereIn('action', ['grades.student_result_viewed', 'grades.student_card_viewed'])
            ->orderByDesc('occurred_at')
            ->limit(self::LIMIT)
            ->get(['id', 'action', 'occurred_at', 'actor_id']);

        // Recorded per teacher with the section in the metadata; filtered
        // here, as JSON comparisons differ between the databases.
        $teachers = AuditEvent::query()
            ->where('action', 'grades.teacher_results_viewed')
            ->orderByDesc('occurred_at')
            ->limit(self::LIMIT * 5)
            ->get(['id', 'action', 'occurred_at', 'subject_id', 'metadata'])
            ->filter(fn (AuditEvent $event): bool => (int) ($event->metadata['stage'] ?? 0) === $student->stage->value
                && ($event->metadata['section'] ?? null) === $student->section->value);

        $users = User::query()->whereIn('id', $own->pluck('actor_id')->filter()->unique())->pluck('name', 'id');
        $names = Teacher::query()->whereIn('id', $teachers->pluck('subject_id')->map(fn ($id): int => (int) $id)->unique())->pluck('name', 'id');

        $rows = [];
        foreach ($own as $event) {
            $byTheStudent = $event->action === 'grades.student_result_viewed';
            $rows[] = [
                'at' => CarbonImmutable::parse($event->occurred_at)->toIso8601String(),
                'who' => $byTheStudent ? $student->full_name : (string) ($users[$event->actor_id] ?? __('A user')),
                'kind' => $byTheStudent ? 'student' : 'user',
            ];
        }
        foreach ($teachers as $event) {
            $rows[] = [
                'at' => CarbonImmutable::parse($event->occurred_at)->toIso8601String(),
                'who' => (string) ($names[(int) $event->subject_id] ?? __('A teacher')),
                'kind' => 'teacher',
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return array_slice($rows, 0, self::LIMIT);
    }
}
