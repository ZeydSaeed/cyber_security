import { Link } from '@inertiajs/react';
import { X } from 'lucide-react';
import { overview } from '@/actions/App/Modules/Grades/Http/GradesAdminController';
import { PageToolbarNav, toolButton } from '@/components/page-toolbar';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';
import {
    LinksIcon,
    PermissionsIcon,
    ResultCardIcon,
    StudentAccessIcon,
    SubjectsIcon,
    TeacherAccessIcon,
    TeacherViewIcon,
    WindowIcon,
} from './settings-icons';
import type { SettingsIcon } from './settings-icons';

export type SettingsTab =
    | 'window'
    | 'links'
    | 'teachers'
    | 'students'
    | 'permissions'
    | 'card'
    | 'teacher-view'
    | 'subjects';

export type TabInfo = {
    key: SettingsTab;
    label: string;
    /** A second line under the label on the landing card. */
    subtitle?: string;
    icon: SettingsIcon;
    /** One line on the landing card: the tab's state at a glance. */
    summary: string;
    /** Something waits for the owner here (requests, appeals). */
    attention?: number;
};

/** The address of one tab, or of the list of tabs. */
export const tabHref = (tab: SettingsTab | null) =>
    tab === null ? overview.url() : overview.url({ query: { tab } });

/**
 * The settings' tabs (owner's request, 2026-11-19), in the order the owner
 * listed them. Those who only read results see the first and the last.
 */
export function useSettingsTabs(
    canManage: boolean,
    summaries: Partial<
        Record<SettingsTab, { summary: string; attention?: number }>
    >,
): TabInfo[] {
    const { t } = useTranslations();
    const all: Omit<TabInfo, 'summary' | 'attention'>[] = [
        {
            key: 'window',
            label: t('Open mark entry to the teaching staff'),
            subtitle: t('Announce the results to students'),
            icon: WindowIcon,
        },
        {
            key: 'links',
            label: t('Sign-in links for teachers and students'),
            icon: LinksIcon,
        },
        {
            key: 'teachers',
            label: t('Teachers’ sign-in and mark editing'),
            icon: TeacherAccessIcon,
        },
        {
            key: 'students',
            label: t('Students’ sign-in and results'),
            icon: StudentAccessIcon,
        },
        {
            key: 'permissions',
            label: t('Each teacher’s own permissions'),
            icon: PermissionsIcon,
        },
        {
            key: 'card',
            label: t('Students’ results: layout and settings'),
            icon: ResultCardIcon,
        },
        {
            key: 'teacher-view',
            label: t('What teachers see'),
            icon: TeacherViewIcon,
        },
        {
            key: 'subjects',
            label: t('Subjects and teachers'),
            icon: SubjectsIcon,
        },
    ];

    return all
        .filter(
            (tab) =>
                canManage || tab.key === 'window' || tab.key === 'subjects',
        )
        .map((tab) => ({
            ...tab,
            summary: summaries[tab.key]?.summary ?? '',
            attention: summaries[tab.key]?.attention,
        }));
}

/**
 * Inside a tab: a Close button back to the cards, beside the previous, next
 * and home buttons (owner's request, 2026-11-19). The tabs' bar is gone:
 * the cards on /admin are the only way between tabs.
 */
export function SettingsClose() {
    const { t } = useTranslations();

    return (
        <PageToolbarNav>
            <Link
                prefetch
                href={tabHref(null)}
                className={cn(
                    'inline-flex h-7 items-center gap-1 rounded-md border px-2 text-sm font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                    toolButton,
                )}
                data-test="close-tab"
            >
                <X className="size-4" aria-hidden />
                {t('Close')}
            </Link>
        </PageToolbarNav>
    );
}

/**
 * Each card's own colour, in the tabs' order: a light solid background and
 * the picture's outline (owner's request, 2026-11-20; no stripe, no arrow).
 * Written out whole so Tailwind finds them.
 */
const tileColours = [
    'bg-tile-1-soft [&_[data-tile]]:text-tile-1',
    'bg-tile-2-soft [&_[data-tile]]:text-tile-2',
    'bg-tile-3-soft [&_[data-tile]]:text-tile-3',
    'bg-tile-4-soft [&_[data-tile]]:text-tile-4',
    'bg-tile-5-soft [&_[data-tile]]:text-tile-5',
    'bg-tile-6-soft [&_[data-tile]]:text-tile-6',
    'bg-tile-7-soft [&_[data-tile]]:text-tile-7',
    'bg-tile-8-soft [&_[data-tile]]:text-tile-8',
] as const;

/** The tabs' order fixes each one's colour, whoever sees them. */
const tileOrder: SettingsTab[] = [
    'window',
    'links',
    'teachers',
    'students',
    'permissions',
    'card',
    'teacher-view',
    'subjects',
];

/**
 * /admin without a tab: one card per tab, centred on the page, the picture
 * above the label and the tab's state at a glance below it.
 */
export function SettingsLanding({ tabs }: { tabs: TabInfo[] }) {
    const { t } = useTranslations();

    return (
        <ul
            className="mx-auto flex w-full max-w-6xl flex-wrap justify-center gap-4"
            data-test="settings-landing"
        >
            {tabs.map((tab) => (
                <li key={tab.key} className="w-full sm:w-64">
                    <Link
                        prefetch
                        href={tabHref(tab.key)}
                        className={cn(
                            'relative flex h-full flex-col items-center gap-2 rounded-lg p-5 text-center shadow-xs transition-shadow hover:shadow-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            tileColours[tileOrder.indexOf(tab.key)],
                        )}
                        data-test={`card-${tab.key}`}
                    >
                        {tab.attention ? (
                            <span className="absolute end-3 top-3 rounded-full bg-primary px-1.5 text-xs font-semibold text-primary-foreground tabular-nums">
                                <span className="sr-only">
                                    {t('waiting:')}{' '}
                                </span>
                                {tab.attention}
                            </span>
                        ) : null}
                        <span data-tile>
                            <tab.icon className="size-14" />
                        </span>
                        <span className="font-semibold text-foreground">
                            {tab.label}
                            {tab.subtitle && (
                                <span className="block">{tab.subtitle}</span>
                            )}
                        </span>
                        {tab.summary && (
                            <span className="text-sm text-muted-foreground">
                                {tab.summary}
                            </span>
                        )}
                    </Link>
                </li>
            ))}
        </ul>
    );
}
