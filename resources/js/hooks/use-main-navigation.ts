import { usePage } from '@inertiajs/react';
import {
    BookOpen,
    DatabaseBackup,
    ChartColumn,
    FileSpreadsheet,
    GraduationCap,
    LayoutDashboard,
    UserRound,
    Users,
} from 'lucide-react';
// Named imports, not the controllers' default objects: every page loads
// this file, and a default object brings all of a controller's routes into
// the base bundle (ADR-0024 budget).
import {
    overview as gradesOverview,
    subjects as gradesSubjects,
    teachers as gradesTeachers,
} from '@/actions/App/Modules/Grades/Http/GradesAdminController';
import academicReports from '@/actions/App/Modules/Grades/Http/ReportsController';
import { index as resultsIndex } from '@/actions/App/Modules/Grades/Http/ResultsController';
import { index as studentsIndex } from '@/actions/App/Modules/Grades/Http/StudentsAdminController';
import { index as backupIndex } from '@/routes/admin/backup';
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
    // (2026-11-15); the Grades overview is the home page. It is called
    // "Settings" from 2026-11-19 and "Control panel" since 2026-11-20
    // (owner's requests): it holds the department's settings for marks,
    // access and results.
    const items: (NavItem & { permission?: string })[] = [
        {
            title: 'Control panel',
            href: gradesOverview(),
            icon: LayoutDashboard,
            permission: 'grades.view_results',
        },
        // The Grades sections, moved here from the overview's buttons
        // (owner's request, 2026-11-16).
        {
            title: 'Teachers',
            href: gradesTeachers(),
            icon: UserRound,
            permission: 'grades.manage',
        },
        {
            title: 'Students',
            href: studentsIndex(),
            icon: GraduationCap,
            permission: 'grades.view_results',
        },
        {
            title: 'Subjects',
            href: gradesSubjects(),
            icon: BookOpen,
            permission: 'grades.manage',
        },
        {
            title: 'Results',
            href: resultsIndex(),
            icon: FileSpreadsheet,
            permission: 'grades.view_results',
        },
        {
            title: 'Users',
            href: usersIndex(),
            icon: Users,
            permission: 'roles.manage',
        },
        // Last, the academic reports (owner's request, 2026-11-20).
        {
            title: 'Academic reports',
            href: academicReports(),
            icon: ChartColumn,
            permission: 'grades.view_results',
        },
        // Under the reports, a plain copy of the database (owner's
        // request, 2026-11-21).
        {
            title: 'Database backup',
            href: backupIndex(),
            icon: DatabaseBackup,
            permission: 'roles.manage',
        },
    ];

    return items
        .filter(
            (item) => !item.permission || permissions.includes(item.permission),
        )
        .map(({ permission: _permission, ...item }) => item);
}
