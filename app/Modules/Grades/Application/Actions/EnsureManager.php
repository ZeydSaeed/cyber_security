<?php

namespace App\Modules\Grades\Application\Actions;

use App\Access\Permission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The owner's actions re-read the person's permissions inside their
 * transaction (building-a-module.md §4): a role removed a moment ago no
 * longer counts.
 */
final class EnsureManager
{
    public static function check(User $actor): void
    {
        $actor->flushRoles();

        if (! $actor->hasPermission(Permission::GradesManage)) {
            throw new AuthorizationException;
        }
    }
}
