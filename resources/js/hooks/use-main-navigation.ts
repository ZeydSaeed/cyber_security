import { usePage } from '@inertiajs/react';
import {
    BarChart3,
    Bell,
    ClipboardList,
    LayoutGrid,
    Users,
} from 'lucide-react';
import GradesAdminController from '@/actions/App/Modules/Grades/Http/GradesAdminController';
import { dashboard } from '@/routes';
import { reports } from '@/routes/admin';
import { index as usersIndex } from '@/routes/admin/users';
import { index as notificationsIndex } from '@/routes/notifications';
import type { NavItem } from '@/types';

/**
 * The main navigation, for the sidebar and the header layouts alike. An
 * item needing a permission shows only to people who have it; the server
 * still refuses the page to anyone else. Titles are English keys,
 * translated where they are shown.
 */
export function useMainNavigation(): NavItem[] {
    const { permissions } = usePage().props.auth;

    const items: (NavItem & { permission?: string })[] = [
        { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
        { title: 'Notifications', href: notificationsIndex(), icon: Bell },
        // The Grades module (ADR-0034).
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
        {
            title: 'Reports',
            href: reports(),
            icon: BarChart3,
            permission: 'audit.view',
        },
    ];

    return items
        .filter(
            (item) => !item.permission || permissions.includes(item.permission),
        )
        .map(({ permission: _permission, ...item }) => item);
}
