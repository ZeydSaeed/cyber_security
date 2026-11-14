<?php

namespace App\Access;

/**
 * Permissions of this application. Each case is a Gate ability of the same
 * name (`can:roles.manage`). Business modules add their own cases here, and
 * every grant is written down in tests/Feature/Access/PermissionMatrixTest.php.
 */
enum Permission: string
{
    case RolesManage = 'roles.manage';
    case AuditView = 'audit.view';

    // Grades module (ADR-0034): teachers, subjects, students, the entry
    // window and corrections; reading the results.
    case GradesManage = 'grades.manage';
    case GradesViewResults = 'grades.view_results';
}
