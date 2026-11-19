import { Link } from '@inertiajs/react';
import {
    BookOpen,
    ChevronRight,
    Eye,
    FileText,
    GraduationCap,
    KeyRound,
    Link2,
    LockOpen,
    UserRound,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { overview } from '@/actions/App/Modules/Grades/Http/GradesAdminController';
import { PageToolbarNav, toolButton } from '@/components/page-toolbar';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';

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
    icon: LucideIcon;
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
            label: t('Mark entry and publication'),
            icon: LockOpen,
        },
        {
            key: 'links',
            label: t('Teachers’ and students’ links'),
            icon: Link2,
        },
        {
            key: 'teachers',
            label: t('Teachers: sign-in and editing'),
            icon: UserRound,
        },
        {
            key: 'students',
            label: t('Students: sign-in and the result'),
            icon: GraduationCap,
        },
        {
            key: 'permissions',
            label: t("Teachers' permissions"),
            icon: KeyRound,
        },
        { key: 'card', label: t('The result card'), icon: FileText },
        { key: 'teacher-view', label: t('What teachers see'), icon: Eye },
        { key: 'subjects', label: t('Subjects and teachers'), icon: BookOpen },
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
 * Each card's own colour (owner's request, 2026-11-19), in the tabs' order:
 * a stripe and the icon's square. Written out whole so Tailwind finds them.
 */
const tileColours = [
    'border-t-tile-1 [&_[data-tile]]:bg-tile-1 [&_[data-tile]]:text-tile-1-foreground',
    'border-t-tile-2 [&_[data-tile]]:bg-tile-2 [&_[data-tile]]:text-tile-2-foreground',
    'border-t-tile-3 [&_[data-tile]]:bg-tile-3 [&_[data-tile]]:text-tile-3-foreground',
    'border-t-tile-4 [&_[data-tile]]:bg-tile-4 [&_[data-tile]]:text-tile-4-foreground',
    'border-t-tile-5 [&_[data-tile]]:bg-tile-5 [&_[data-tile]]:text-tile-5-foreground',
    'border-t-tile-6 [&_[data-tile]]:bg-tile-6 [&_[data-tile]]:text-tile-6-foreground',
    'border-t-tile-7 [&_[data-tile]]:bg-tile-7 [&_[data-tile]]:text-tile-7-foreground',
    'border-t-tile-8 [&_[data-tile]]:bg-tile-8 [&_[data-tile]]:text-tile-8-foreground',
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

/** /admin without a tab: one card per tab, with its state at a glance. */
export function SettingsLanding({ tabs }: { tabs: TabInfo[] }) {
    const { t } = useTranslations();

    return (
        <ul
            className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
            data-test="settings-landing"
        >
            {tabs.map((tab) => (
                <li key={tab.key}>
                    <Link
                        href={tabHref(tab.key)}
                        className={cn(
                            'flex h-full flex-col gap-2 rounded-lg border border-t-4 border-border bg-card p-4 shadow-xs transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            tileColours[tileOrder.indexOf(tab.key)],
                        )}
                        data-test={`card-${tab.key}`}
                    >
                        <span className="flex items-center gap-2 font-semibold text-foreground">
                            <span
                                className="grid size-8 place-items-center rounded-md"
                                data-tile
                            >
                                <tab.icon className="size-4" aria-hidden />
                            </span>
                            <span className="flex-1">{tab.label}</span>
                            {tab.attention ? (
                                <span className="rounded-full bg-primary px-1.5 text-xs font-semibold text-primary-foreground tabular-nums">
                                    <span className="sr-only">
                                        {t('waiting:')}{' '}
                                    </span>
                                    {tab.attention}
                                </span>
                            ) : null}
                            <ChevronRight
                                className="size-4 text-muted-foreground rtl:-scale-x-100"
                                aria-hidden
                            />
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
