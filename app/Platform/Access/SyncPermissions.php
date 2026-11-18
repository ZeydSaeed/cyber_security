<?php

namespace App\Platform\Access;

use App\Platform\Access\Contracts\HoldsRoles;
use App\Platform\Audit\AuditLogger;
use App\Platform\Exceptions\DomainRuleViolation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Sets the permissions granted to one user directly (owner's request,
 * 2026-11-18). Like roles: nobody changes their own, the actor's right to
 * manage is re-checked under the lock, and each grant or removal is
 * audited. Unknown permission values are ignored.
 */
class SyncPermissions
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<string>  $permissions
     */
    public function handle(Model&HoldsRoles&Authenticatable $actor, Model&HoldsRoles $target, array $permissions): void
    {
        $known = array_map(fn ($permission): string => (string) $permission->value, AccessCatalog::permissions());
        $wanted = array_values(array_unique(array_intersect($permissions, $known)));

        DB::transaction(function () use ($actor, $target, $wanted): void {
            $target->newQuery()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            $held = PermissionGrant::query()->where('user_id', $target->getKey())->pluck('permission')->all();
            $adding = array_values(array_diff($wanted, $held));
            $removing = array_values(array_diff($held, $wanted));

            if ($adding === [] && $removing === []) {
                return;
            }

            if ($actor->is($target)) {
                throw new DomainRuleViolation(__('You cannot change your own permissions.'), 'permissions');
            }

            $actor->flushRoles();

            if (! $actor->hasPermission(AccessCatalog::managePermission())) {
                throw new AuthorizationException;
            }

            foreach ($adding as $permission) {
                $grant = new PermissionGrant;
                $grant->forceFill([
                    'user_id' => $target->getKey(),
                    'permission' => $permission,
                    'granted_by' => $actor->getKey(),
                ])->save();

                $this->audit->record('access.permission_granted', $target, ['permission' => $permission], actor: $actor);
            }

            if ($removing !== []) {
                PermissionGrant::query()->where('user_id', $target->getKey())->whereIn('permission', $removing)->delete();

                foreach ($removing as $permission) {
                    $this->audit->record('access.permission_revoked', $target, ['permission' => $permission], actor: $actor);
                }
            }
        }, 3);

        $target->flushRoles();
    }
}
