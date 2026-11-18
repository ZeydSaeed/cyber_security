import type { useTranslations } from '@/hooks/use-translations';

// Role and permission names as people read them. Kept apart from the
// activity timeline so that pages needing only a label (users, reports,
// notifications) do not load the timeline (ADR-0024 budget).

type Translate = ReturnType<typeof useTranslations>['t'];

/** A role as people read it. */
export function roleLabel(t: Translate, role: string): string {
    switch (role) {
        case 'administrator':
            return t('Administrator');
        case 'auditor':
            return t('Auditor');
        default:
            return role;
    }
}

/** A permission as people read it. */
export function permissionLabel(t: Translate, permission: string): string {
    switch (permission) {
        case 'roles.manage':
            return t('Manage users and their access');
        case 'audit.view':
            return t('Read the audit trail');
        case 'grades.manage':
            return t('Manage grades');
        case 'grades.view_results':
            return t('View results');
        default:
            return permission;
    }
}
