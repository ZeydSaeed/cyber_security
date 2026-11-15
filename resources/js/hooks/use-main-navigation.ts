import { usePage } from '@inertiajs/react';
import { ClipboardList, Users } from 'lucide-react';
import GradesAdminController from '@/actions/App/Modules/Grades/Http/GradesAdminController';
import { index as usersIndex } from '@/routes/admin/users';
import type { NavItem } from '@/types';

/**
 * The main navigation, for the sidebar and the header layouts alike. An
 * item needing a permission shows only to people who have it; the server
 * still refuses the page to anyone else. Titles are English keys,
 * translated where they are shown.
 */
export function useMainNavigation(): NavItem[] {
    const { permissions } = usePage().props.auth;

    // The owner removed the dashboard, notifications and reports
    // (2026-11-15); the Grades overview is the home page.
    const items: (NavItem & { permission?: string })[] = [
        {
            title: 'First-month marks',
            href: GradesAdminController.overview(),
            icon: ClipboardList,
            permission: 'grades.view_results',
        },
        {
            title: 'Users',
            href: usersIndex(),
            icon: Users,
            permission: 'roles.manage',
        },
    ];

    return items
        .filter(
            (item) => !item.permission || permissions.includes(item.permission),
        )
        .map(({ permission: _permission, ...item }) => item);
}
