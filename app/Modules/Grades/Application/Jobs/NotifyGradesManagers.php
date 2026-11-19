<?php

namespace App\Modules\Grades\Application\Jobs;

use App\Access\Permission;
use App\Models\User;
use App\Modules\Grades\Application\Notifications\GradesNotification;
use App\Platform\Access\AccessCatalog;
use App\Platform\Access\RoleAssignment;
use App\Platform\Outbox\OutboxJob;
use Illuminate\Support\Facades\Notification;

/**
 * grades.notify_managers: tells everyone who manages grades that something
 * waits for them (ADR-0042): a teacher's request to change marks, a
 * student's appeal. The payload carries the kind and the facts; the
 * interface writes the sentence. Runs once per message (OutboxJob).
 */
class NotifyGradesManagers extends OutboxJob
{
    public const TOPIC = 'grades.notify_managers';

    protected function process(array $payload): void
    {
        $kind = (string) ($payload['kind'] ?? '');
        if (! in_array($kind, [GradesNotification::EDIT_REQUESTED, GradesNotification::APPEAL_SUBMITTED, GradesNotification::APPEAL_ANSWERED, GradesNotification::APPEALS_CLOSING, GradesNotification::APPEALS_OVERDUE], true)) {
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

        $facts = array_map('strval', array_filter((array) ($payload['facts'] ?? []), 'is_scalar'));

        Notification::send($managers, new GradesNotification($kind, $facts));
    }
}
