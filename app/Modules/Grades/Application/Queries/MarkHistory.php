<?php

namespace App\Modules\Grades\Application\Queries;

use App\Models\User;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Platform\Audit\AuditEvent;
use Carbon\CarbonImmutable;

/**
 * Every change of a section's marks in one subject (ADR-0041), newest
 * first: when, who (the teacher, or the user who corrected), which
 * student, from what to what. Read from the audit records SaveMarks
 * writes, so nothing is kept twice. Three queries.
 */
final class MarkHistory
{
    public const LIMIT = 300;

    /**
     * @return list<array{at: string, by: string, owner: bool, student: string, student_uuid: string, from: int|string|null, to: int|string|null}>
     */
    public function for(Subject $subject, Section $section, Period $period = Period::Month1): array
    {
        $events = AuditEvent::query()
            ->where('action', 'grades.marks_saved')
            ->where('subject_type', Subject::MORPH)
            ->where('subject_id', (string) $subject->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['id', 'occurred_at', 'actor_id', 'changes', 'metadata'])
            ->filter(fn (AuditEvent $event): bool => ($event->metadata['section'] ?? null) === $section->value
                && ($event->metadata['period'] ?? null) === $period->value);

        $uuids = $events->flatMap(fn (AuditEvent $event): array => array_keys((array) $event->changes))->unique()->values();
        $names = Student::query()->whereIn('uuid', $uuids)->pluck('full_name', 'uuid');
        $users = User::query()->whereIn('id', $events->pluck('actor_id')->filter()->unique())->pluck('name', 'id');

        $rows = [];
        foreach ($events as $event) {
            $by = $event->actor_id !== null
                ? (string) ($users[$event->actor_id] ?? __('A user'))
                : (string) ($event->metadata['teacher_name'] ?? __('A teacher'));

            foreach ((array) $event->changes as $uuid => $change) {
                $rows[] = [
                    'at' => CarbonImmutable::parse($event->occurred_at)->toIso8601String(),
                    'by' => $by,
                    'owner' => $event->actor_id !== null,
                    'student' => (string) ($names[$uuid] ?? __('A deleted student')),
                    // Groups one mark's journey, first entry to last change (ADR-0043).
                    'student_uuid' => (string) $uuid,
                    'from' => is_array($change) ? ($change['from'] ?? null) : null,
                    'to' => is_array($change) ? ($change['to'] ?? null) : null,
                ];
            }
        }

        return $rows;
    }

    /**
     * One student's marks, each from its first entry to its last change,
     * oldest first (ADR-0044): for the owner's page of that student. Reads
     * only the records that touch the student. Three queries.
     *
     * @return list<array{at: string, by: string, owner: bool, subject: string, from: int|string|null, to: int|string|null}>
     */
    public function forStudent(Student $student, Period $period = Period::Month1): array
    {
        $events = AuditEvent::query()
            ->where('action', 'grades.marks_saved')
            ->where('subject_type', Subject::MORPH)
            ->whereJsonContainsKey('changes->'.$student->uuid)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get(['id', 'occurred_at', 'actor_id', 'subject_id', 'changes', 'metadata'])
            ->filter(fn (AuditEvent $event): bool => ($event->metadata['period'] ?? null) === $period->value);

        $subjects = Subject::query()->whereIn('id', $events->pluck('subject_id')->unique()->map(fn ($id): int => (int) $id))->pluck('name', 'id');
        $users = User::query()->whereIn('id', $events->pluck('actor_id')->filter()->unique())->pluck('name', 'id');

        $rows = [];
        foreach ($events as $event) {
            $change = ((array) $event->changes)[$student->uuid] ?? null;
            $rows[] = [
                'at' => CarbonImmutable::parse($event->occurred_at)->toIso8601String(),
                'by' => $event->actor_id !== null
                    ? (string) ($users[$event->actor_id] ?? __('A user'))
                    : (string) ($event->metadata['teacher_name'] ?? __('A teacher')),
                'owner' => $event->actor_id !== null,
                'subject' => (string) ($subjects[(int) $event->subject_id] ?? __('A deleted subject')),
                'from' => is_array($change) ? ($change['from'] ?? null) : null,
                'to' => is_array($change) ? ($change['to'] ?? null) : null,
            ];
        }

        return $rows;
    }
}
