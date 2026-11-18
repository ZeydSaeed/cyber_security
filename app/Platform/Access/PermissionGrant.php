<?php

namespace App\Platform\Access;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One permission granted to one user directly, besides their roles. Rows
 * change only through SyncPermissions, which checks and audits.
 *
 * @property int $id
 * @property int $user_id
 * @property string $permission
 * @property int|null $granted_by
 * @property CarbonImmutable $created_at
 */
class PermissionGrant extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
