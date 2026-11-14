<?php

namespace App\Access;

use App\Platform\Access\Contracts\GrantsPermissions;

/**
 * Roles of this application. Permissions are listed explicitly, never with
 * Permission::cases(), so a new permission reaches nobody until it is added
 * here and to the permission matrix test (ADR-0014).
 *
 * Teachers are not users and hold no role: they enter marks with a code on
 * their own guard (ADR-0034).
 */
enum Role: string implements GrantsPermissions
{
    case Administrator = 'administrator';
    case Auditor = 'auditor';

    public function permissions(): array
    {
        return match ($this) {
            self::Administrator => [Permission::RolesManage, Permission::AuditView, Permission::GradesManage, Permission::GradesViewResults],
            self::Auditor => [Permission::AuditView],
        };
    }
}
