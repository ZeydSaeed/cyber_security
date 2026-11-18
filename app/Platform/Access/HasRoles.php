<?php

namespace App\Platform\Access;

use App\Platform\Access\Contracts\GrantsPermissions;
use BackedEnum;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Roles and permissions for the user model (ADR-0014). A user holds the
 * permissions of their roles plus any granted to them directly
 * (permission_grants, 2026-11-18). Both are loaded once per model
 * instance; call flushRoles() after grants change.
 */
trait HasRoles
{
    /** @var list<GrantsPermissions>|null */
    private ?array $resolvedRoles = null;

    /**
     * @return HasMany<RoleAssignment, $this>
     */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    /**
     * @return list<GrantsPermissions>
     */
    public function roles(): array
    {
        if ($this->resolvedRoles !== null) {
            return $this->resolvedRoles;
        }

        $roles = [];

        foreach ($this->roleAssignments as $assignment) {
            $role = AccessCatalog::role($assignment->role);

            if ($role !== null) {
                $roles[] = $role;
            }
        }

        return $this->resolvedRoles = $roles;
    }

    public function hasRole(GrantsPermissions $role): bool
    {
        return in_array($role, $this->roles(), true);
    }

    /**
     * @return HasMany<PermissionGrant, $this>
     */
    public function permissionGrants(): HasMany
    {
        return $this->hasMany(PermissionGrant::class);
    }

    /**
     * Permissions granted directly, not through a role.
     *
     * @return list<string>
     */
    public function directPermissions(): array
    {
        $known = array_map(fn (BackedEnum $permission): string => (string) $permission->value, AccessCatalog::permissions());

        return array_values(array_intersect($this->permissionGrants->pluck('permission')->all(), $known));
    }

    /**
     * Every permission held, through roles or directly, sorted.
     *
     * @return list<string>
     */
    public function permissionValues(): array
    {
        $values = $this->directPermissions();

        foreach ($this->roles() as $role) {
            foreach ($role->permissions() as $granted) {
                $values[] = (string) $granted->value;
            }
        }

        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    public function hasPermission(BackedEnum|string $permission): bool
    {
        $value = (string) ($permission instanceof BackedEnum ? $permission->value : $permission);

        return in_array($value, $this->permissionValues(), true);
    }

    public function flushRoles(): void
    {
        $this->resolvedRoles = null;
        $this->unsetRelation('roleAssignments');
        $this->unsetRelation('permissionGrants');
    }
}
