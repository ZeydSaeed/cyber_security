import { Link } from '@inertiajs/react';
import { overview } from '@/actions/App/Modules/Grades/Http/GradesAdminController';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';
import {
    AppealsIcon,
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
import { TileBadge, tileClasses } from './status-tiles';
import type { Tile } from './status-tiles';

export type SettingsTab =
    | 'window'
    | 'links'
    | 'teachers'
    | 'students'
    | 'permissions'
    | 'card'
    | 'teacher-view'
    | 'subjects'
    | 'appeals';

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
    /** The large number on the landing card (owner's picture, 2026-11-20). */
    count?: number | string;
    /** How far along the tab's work is (owner's request, 2026-11-20). */
    progress?: TabProgress;
};

/**
 * A card's progress: what is done of what is due, and what that is.
 * Nothing due: no percentage.
 */
export type TabProgress = { done: number; of: number; label: string };

/** The whole percentage, or null when nothing is due. */
export const percentOf = (progress?: TabProgress): number | null =>
    progress === undefined || progress.of <= 0
        ? null
        : Math.round(
              (Math.min(progress.done, progress.of) / progress.of) * 100,
          );

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
        Record<
            SettingsTab,
            {
                summary: string;
                attention?: number;
                count?: number | string;
                progress?: TabProgress;
            }
        >
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
            // A tab of its own (owner's request, 2026-11-20), beside the
            // students' one it grew out of.
            key: 'appeals',
            label: t("Students' appeals"),
            icon: AppealsIcon,
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
            count: summaries[tab.key]?.count,
            progress: summaries[tab.key]?.progress,
        }));
}

/** The tabs' order fixes each one's colour, whoever sees them. */
const tileOf: Record<SettingsTab, Tile> = {
    window: 1,
    links: 2,
    teachers: 3,
    students: 4,
    permissions: 5,
    card: 6,
    'teacher-view': 7,
    subjects: 8,
    appeals: 9,
};

/**
 * The picture's bar over the cards (owner's request, 2026-11-20): one
 * segment per card in its colour, filled as far as the card's work is
 * done, and a legend with each card's percentage. The cards count
 * different things, so each segment has its own scale: the segments do
 * not add up to one whole.
 */
export function ProgressOverview({ tabs }: { tabs: TabInfo[] }) {
    const { t } = useTranslations();
    const measured = tabs.filter((tab) => tab.progress !== undefined);

    if (measured.length === 0) {
        return null;
    }

    return (
        <section
            className="mx-auto grid w-full max-w-6xl gap-4 rounded-lg border bg-card p-4"
            aria-labelledby="progress-title"
            data-test="progress-overview"
        >
            <h2 id="progress-title" className="font-semibold">
                {t('Progress of each card')}
            </h2>
            <div className="flex h-3 w-full gap-1" aria-hidden>
                {measured.map((tab) => (
                    <span
                        key={tab.key}
                        className="h-full flex-1 overflow-hidden rounded-full bg-muted"
                    >
                        <span
                            className={cn(
                                'block h-full rounded-full',
                                tileClasses[tileOf[tab.key]].solid,
                            )}
                            style={{
                                width: `${percentOf(tab.progress) ?? 0}%`,
                            }}
                        />
                    </span>
                ))}
            </div>
            <ul className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
                {measured.map((tab) => {
                    const percent = percentOf(tab.progress);

                    return (
                        <li
                            key={tab.key}
                            className="flex items-center gap-2"
                            data-test={`progress-${tab.key}`}
                        >
                            <span
                                className={cn(
                                    'size-3 shrink-0 rounded-full',
                                    tileClasses[tileOf[tab.key]].solid,
                                )}
                                aria-hidden
                            />
                            <span className="font-medium">
                                {tab.progress?.label}
                            </span>
                            <span className="text-muted-foreground tabular-nums">
                                · {percent === null ? '—' : `${percent}%`}
                            </span>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}

/** The card's own progress bar, under its number. */
function CardProgress({ tab }: { tab: TabInfo }) {
    const percent = percentOf(tab.progress);

    if (tab.progress === undefined) {
        return null;
    }

    return (
        <span className="mt-auto grid w-full gap-1 pt-1">
            <span className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
                <span>{tab.progress.label}</span>
                <span className="font-semibold text-foreground tabular-nums">
                    {percent === null ? '—' : `${percent}%`}
                </span>
            </span>
            <span
                role="progressbar"
                aria-label={tab.progress.label}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={percent ?? 0}
                className="block h-2 w-full overflow-hidden rounded-full bg-card"
            >
                <span
                    className={cn(
                        'block h-full rounded-full',
                        tileClasses[tileOf[tab.key]].solid,
                    )}
                    style={{ width: `${percent ?? 0}%` }}
                />
            </span>
        </span>
    );
}

/**
 * /admin without a tab: one card per tab in the owner's picture
 * (2026-11-20): a soft card with a faint border, a solid round badge, the
 * label, a large number, and the tab's state at a glance below it.
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
                            'relative flex h-full flex-col items-center gap-2 rounded-xl border p-5 text-center shadow-xs transition-shadow hover:shadow-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            tileClasses[tileOf[tab.key]].card,
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
                        <TileBadge
                            tile={tileOf[tab.key]}
                            icon={tab.icon}
                            size="lg"
                        />
                        <span className="font-semibold text-foreground">
                            {tab.label}
                            {tab.subtitle && (
                                <span className="block">{tab.subtitle}</span>
                            )}
                        </span>
                        {tab.count !== undefined && (
                            <span
                                className="text-3xl font-bold text-foreground tabular-nums"
                                data-test="card-count"
                            >
                                {tab.count}
                            </span>
                        )}
                        {tab.summary && (
                            <span className="text-sm text-muted-foreground">
                                {tab.summary}
                            </span>
                        )}
                        <CardProgress tab={tab} />
                    </Link>
                </li>
            ))}
        </ul>
    );
}
