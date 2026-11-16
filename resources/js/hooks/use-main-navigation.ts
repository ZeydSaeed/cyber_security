import { usePage } from '@inertiajs/react';
import {
    BookOpen,
    ClipboardList,
    FileSpreadsheet,
    GraduationCap,
    UserRound,
    Users,
} from 'lucide-react';
import GradesAdminController from '@/actions/App/Modules/Grades/Http/GradesAdminController';
import ResultsController from '@/actions/App/Modules/Grades/Http/ResultsController';
import StudentsAdminController from '@/actions/App/Modules/Grades/Http/StudentsAdminController';
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
        // The Grades sections, moved here from the overview's buttons
        // (owner's request, 2026-11-16).
        {
            title: 'Teachers',
            href: GradesAdminController.teachers(),
            icon: UserRound,
            permission: 'grades.manage',
        },
        {
            title: 'Students',
            href: StudentsAdminController.index(),
            icon: GraduationCap,
            permission: 'grades.view_results',
        },
        {
            title: 'Subjects',
            href: GradesAdminController.subjects(),
            icon: BookOpen,
            permission: 'grades.manage',
        },
        {
            title: 'Results',
            href: ResultsController.index(),
            icon: FileSpreadsheet,
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
