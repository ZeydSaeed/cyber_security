<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Platform\Access\AccessCatalog;
use App\Platform\Access\RoleAssignment;
use App\Platform\Audit\AuditLogger;
use App\Platform\Exceptions\DomainRuleViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * An administrator deletes a person (owner's request, 2026-11-18). Refused
 * for oneself, for the last administrator, and for a person whose records
 * must stay attributed to them (marks they entered). Roles go with the
 * account; the audit trail keeps who it was.
 */
class DeleteUser
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, User $user): void
    {
        if ($actor->is($user)) {
            throw new DomainRuleViolation(__('You cannot delete your own account here.'), 'user');
        }

        try {
            DB::transaction(function () use ($actor, $user): void {
                $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

                $administrators = RoleAssignment::query()
                    ->where('role', AccessCatalog::administrator()->value)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('user_id')
                    ->all();

                if (in_array($user->getKey(), $administrators, false) && count($administrators) <= 1) {
                    throw new DomainRuleViolation(__('The last administrator cannot be removed.'), 'user');
                }

                $roles = RoleAssignment::query()->where('user_id', $user->getKey())->pluck('role')->all();

                $this->audit->record('user.deleted', $user, array_filter([
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => implode(', ', $roles),
                ]), actor: $actor);

                RoleAssignment::query()->where('user_id', $user->getKey())->delete();
                $user->notifications()->delete();
                $user->delete();
            }, 3);
        } catch (QueryException $exception) {
            // A record that keeps this person (restrictOnDelete), such as
            // marks they entered.
            // PostgreSQL reports RESTRICT as 23001 and NO ACTION as 23503;
            // SQLite and MySQL as 23000 (found on PostgreSQL in review).
            if (in_array((string) $exception->getCode(), ['23001', '23503', '23000'], true)) {
                throw new DomainRuleViolation(__('This user has records that keep them; they cannot be deleted.'), 'user');
            }

            throw $exception;
        }
    }
}
