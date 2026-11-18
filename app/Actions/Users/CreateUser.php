<?php

namespace App\Actions\Users;

use App\Access\Role;
use App\Models\User;
use App\Platform\Access\AssignRole;
use App\Platform\Access\SyncPermissions;
use App\Platform\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An administrator adds a person directly (owner's request, 2026-11-18;
 * replaces invitations): name, address, a password the administrator
 * chooses, roles and permissions granted directly. The password is kept
 * as the sign-in hash and, encrypted, as a copy administrators can show.
 * The address is taken as verified because the administrator entered it.
 * The person still sets up a second factor on first sign-in, like everyone.
 */
class CreateUser
{
    public function __construct(
        private readonly AssignRole $assignRole,
        private readonly SyncPermissions $syncPermissions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<Role>  $roles
     * @param  list<string>  $permissions
     */
    public function handle(User $actor, string $name, string $email, string $password, array $roles, array $permissions): User
    {
        return DB::transaction(function () use ($actor, $name, $email, $password, $roles, $permissions): User {
            $user = new User;
            $user->forceFill([
                'name' => $name,
                'email' => Str::lower($email),
                'password' => $password,
                'admin_password' => $password,
                'admin_password_set_at' => now(),
                'email_verified_at' => now(),
            ])->save();

            $roleValues = array_map(fn (Role $role): string => $role->value, $roles);
            $this->audit->record('user.created', $user, $roleValues === [] ? [] : ['role' => implode(', ', $roleValues)], actor: $actor);

            foreach ($roles as $role) {
                $this->assignRole->handle($actor, $user, $role);
            }

            $this->syncPermissions->handle($actor, $user, $permissions);

            return $user;
        }, 3);
    }
}
