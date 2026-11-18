<?php

namespace App\Actions\Users;

use App\Access\Role;
use App\Models\User;
use App\Platform\Access\AssignRole;
use App\Platform\Access\RevokeRole;
use App\Platform\Access\SyncPermissions;
use App\Platform\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An administrator changes a person (owner's request, 2026-11-18): name,
 * address, a new password (optional: blank keeps the current one), roles
 * and direct permissions. Role and permission changes go through
 * AssignRole, RevokeRole and SyncPermissions, so their rules hold (no
 * change to one's own access, the last administrator stays). The password
 * itself is never written to the audit trail.
 */
class UpdateUser
{
    public function __construct(
        private readonly AssignRole $assignRole,
        private readonly RevokeRole $revokeRole,
        private readonly SyncPermissions $syncPermissions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<Role>  $roles
     * @param  list<string>  $permissions
     */
    public function handle(User $actor, User $user, string $name, string $email, ?string $password, array $roles, array $permissions): void
    {
        DB::transaction(function () use ($actor, $user, $name, $email, $password, $roles, $permissions): void {
            $user->forceFill(['name' => $name, 'email' => Str::lower($email)]);
            $changes = AuditLogger::changesOf($user, ['name', 'email']);

            if ($password !== null && $password !== '') {
                $user->forceFill([
                    'password' => $password,
                    'admin_password' => $password,
                    'admin_password_set_at' => now(),
                ]);
            }

            $passwordChanged = $user->isDirty('password');

            if ($user->isDirty()) {
                $user->save();
                $this->audit->record('user.updated', $user, $passwordChanged ? ['password' => 'changed'] : [], $changes, actor: $actor);
            }

            // One's own access is never changed here (the form does not
            // offer it, and AssignRole/RevokeRole would refuse).
            if ($actor->is($user)) {
                return;
            }

            $user->load('roleAssignments');
            $held = $user->roleAssignments->pluck('role')->all();
            $wanted = array_map(fn (Role $role): string => $role->value, $roles);

            foreach (Role::cases() as $role) {
                $has = in_array($role->value, $held, true);
                $wants = in_array($role->value, $wanted, true);

                if ($wants && ! $has) {
                    $this->assignRole->handle($actor, $user, $role);
                } elseif ($has && ! $wants) {
                    $this->revokeRole->handle($actor, $user, $role);
                }
            }

            $this->syncPermissions->handle($actor, $user, $permissions);
        }, 3);
    }
}
