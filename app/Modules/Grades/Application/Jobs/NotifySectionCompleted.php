<?php

namespace App\Modules\Grades\Application\Jobs;

use App\Access\Permission;
use App\Models\User;
use App\Modules\Grades\Application\Notifications\GradesNotification;
use App\Modules\Grades\Models\Subject;
use App\Platform\Access\AccessCatalog;
use App\Platform\Access\RoleAssignment;
use App\Platform\Outbox\OutboxJob;
use Illuminate\Support\Facades\Notification;

/**
 * grades.section_completed: tells everyone who manages grades that a
 * section's marks in a subject are complete (ADR-0034). Runs once per
 * message (OutboxJob).
 */
class NotifySectionCompleted extends OutboxJob
{
    protected function process(array $payload): void
    {
        $subject = Subject::query()->with('teacher')->where('uuid', (string) ($payload['subject'] ?? ''))->first();

        if ($subject === null) {
            return;
        }

        $roles = array_values(array_map(
            fn ($role): string => (string) $role->value,
            array_filter(
                AccessCatalog::roleEnum()::cases(),
                fn ($role): bool => in_array(Permission::GradesManage, $role->permissions(), true),
            ),
        ));

        $managers = User::query()
            ->whereIn('id', RoleAssignment::query()->select('user_id')->whereIn('role', $roles))
            ->get();

        Notification::send($managers, new GradesNotification(GradesNotification::SECTION_COMPLETED, [
            'subject' => $subject->name,
            'stage' => (string) $subject->stage->value,
            'section' => (string) ($payload['section'] ?? ''),
            'teacher' => (string) $subject->teacher?->name,
        ]));
    }
}
