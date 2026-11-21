import { Head } from '@inertiajs/react';
import {
    AlertOctagon,
    AlertTriangle,
    BadgeCheck,
    CheckCircle2,
    Circle,
    CircleDashed,
    ClipboardCheck,
    Eye,
    Hourglass,
    Info,
    Lightbulb,
    LogIn,
    MessageSquareWarning,
    Percent,
    Printer,
    Search,
    UserRound,
    UsersRound,
    XCircle,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import BarChart from '@/components/bar-chart';
import PageToolbar, { toolButton } from '@/components/page-toolbar';
import { Input } from '@/components/ui/input';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';
import { useMoment } from '../../components/access-notice';
import ReportBars from '../../components/report-bars';
import StatusBoard from '../../components/status-board';
import type { ReportBar } from '../../components/report-bars';
import { placeLabel, stageLabel } from '../../place';
import { bandLabel } from '../../result-words';
import type { Band } from '../../types';

type Translate = ReturnType<typeof useTranslations>['t'];

type Outcome = {
    students: number;
    complete: number;
    incomplete: number;
    passed: number;
    failed: number;
    exempt: number;
    pass_rate: number | null;
    fail_rate: number | null;
    exempt_rate: number | null;
    mean: number | null;
    failed_two_or_more: number;
    frequent_absence: number;
    bands: Record<Band, number>;
};

type SectionRow = Outcome & {
    stage: number;
    section: string;
    divided: boolean;
};
type StageRow = Outcome & { stage: number };

type MarkFigures = {
    entered: number;
    absent: number;
    passed: number;
    failed: number;
    exempt: number;
    pass_rate: number | null;
    absence_rate: number | null;
    mean: number | null;
    highest: number | null;
    lowest: number | null;
};

type SubjectRow = MarkFigures & {
    uuid: string;
    name: string;
    stage: number;
    max: number;
    pass: number;
    students: number;
    stage_mean: number | null;
    difference: number | null;
    place: number | null;
    of: number;
    sections: (MarkFigures & {
        section: string;
        teacher: string | null;
        students: number;
    })[];
};

type TeacherRow = {
    uuid: string;
    name: string;
    specialty: string | null;
    active: boolean;
    logins: number;
    last_login_at: string | null;
    complete: number;
    approved: number;
    state: 'approved' | 'complete' | 'started' | 'none' | 'free';
    assignments: {
        subject: string;
        stage: number;
        section: string;
        divided: boolean;
        students: number;
        entered: number;
        approved_at: string | null;
    }[];
};

type StudentRow = {
    uuid: string;
    name: string;
    number: string | null;
    stage: number;
    section: string;
    divided: boolean;
    blocked: boolean;
    logins: number;
    last_login_at: string | null;
    views: number;
    last_view_at: string | null;
};

type AppealRow = {
    uuid: string;
    student: string;
    stage: number;
    section: string;
    divided: boolean;
    subject: string;
    teacher: string | null;
    reason: string;
    status: 'pending' | 'accepted' | 'rejected';
    response: string | null;
    decided_by: string | null;
    decided_by_teacher: boolean;
    created_at: string;
    decided_at: string | null;
};

type MarkDates = {
    subject: string;
    stage: number;
    section: string;
    divided: boolean;
    marks: number;
    first_at: string;
    last_at: string;
    changes: number;
    corrections: number;
    by_owner: number;
};

type Advice = {
    level: 'critical' | 'warning' | 'info';
    area: string;
    text: string;
    action: string;
};

type Props = {
    period: {
        value: string;
        label: string;
        open: boolean;
        opened_at: string | null;
        closed_at: string | null;
        published_at: string | null;
        places: {
            stage: number;
            section: string;
            divided: boolean;
            published_at: string | null;
        }[];
    };
    periods: { value: string; label: string }[];
    totals: Outcome;
    sections: SectionRow[];
    stages: StageRow[];
    top: {
        stage: number;
        divided: boolean;
        students: {
            uuid: string;
            name: string;
            number: string | null;
            section: string;
            percentage: number;
            band: Band | null;
            rank: number;
        }[];
    }[];
    subjects: SubjectRow[];
    teachers: TeacherRow[];
    unassigned: {
        subject: string;
        stage: number;
        section: string;
        divided: boolean;
    }[];
    students: StudentRow[];
    appeals: AppealRow[];
    marks: MarkDates[];
    activity: {
        days: {
            day: string;
            student_logins: number;
            teacher_logins: number;
            views: number;
        }[];
        student_logins: number;
        teacher_logins: number;
        views: number;
    };
    advice: Advice[];
    thresholds: { pass_critical: number; pass_warning: number };
    generatedAt: string;
};

const TABS = [
    'overview',
    'results',
    'subjects',
    'teachers',
    'students',
    'appeals',
    'marks',
    'advice',
] as const;
type Tab = (typeof TABS)[number];

const BANDS: Band[] = [
    'excellent',
    'very_good',
    'good',
    'fair',
    'pass',
    'fail',
];

const percent = (value: number | null) => (value === null ? '—' : `${value}%`);

/**
 * The academic reports (owner's request, 2026-11-20): one period's
 * results, subjects, teachers, students' sign-ins, appeals and the marks'
 * history, each in a tab with tables and charts, and the recommendations
 * the figures call for. Read only; printable.
 */
export default function Reports(props: Props) {
    const { t } = useTranslations();
    const moment = useMoment();
    const [tab, setTab] = useState<Tab>(() => {
        if (typeof window === 'undefined') {
            return 'overview';
        }
        const hash = window.location.hash.slice(1) as Tab;

        return TABS.includes(hash) ? hash : 'overview';
    });
    const choose = (next: Tab) => {
        setTab(next);
        window.history.replaceState(null, '', `#${next}`);
    };
    // A link or the address bar may change only the #tab: follow it.
    useEffect(() => {
        const follow = () => {
            const hash = window.location.hash.slice(1) as Tab;
            if (TABS.includes(hash)) {
                setTab(hash);
            }
        };
        window.addEventListener('hashchange', follow);

        return () => window.removeEventListener('hashchange', follow);
    }, []);

    const labels: Record<Tab, string> = {
        overview: t('Summary'),
        results: t('Results'),
        subjects: t('Subjects'),
        teachers: t('Teachers'),
        students: t('Students’ sign-ins'),
        appeals: t('Appeals'),
        marks: t('Marks’ history'),
        advice: t('Recommendations'),
    };
    const critical = props.advice.filter((one) => one.level === 'critical');

    return (
        <>
            <Head title={t('Academic reports')} />
            <PageToolbar title={t('Academic reports')}>
                <button
                    type="button"
                    onClick={() => window.print()}
                    className={cn(
                        'inline-flex h-7 items-center gap-1 rounded-md border px-2 text-sm font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                        toolButton,
                    )}
                    data-test="print-report"
                >
                    <Printer className="size-4" aria-hidden />
                    {t('Print')}
                </button>
            </PageToolbar>

            <div className="mx-auto flex w-full max-w-7xl flex-col gap-4 p-4">
                <header className="flex flex-wrap items-baseline justify-between gap-2">
                    <div>
                        <p className="text-lg font-semibold">
                            {t('Results of :period', {
                                period: props.period.label,
                            })}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {props.period.published_at
                                ? t('Published to every section on :date.', {
                                      date: moment(props.period.published_at),
                                  })
                                : t(':count of :total sections published.', {
                                      count: String(
                                          props.period.places.filter(
                                              (place) =>
                                                  place.published_at !== null,
                                          ).length,
                                      ),
                                      total: String(props.period.places.length),
                                  })}{' '}
                            {props.period.open
                                ? t('Mark entry is open.')
                                : t('Mark entry is closed.')}
                        </p>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {t('Figures as of :date.', {
                            date: moment(props.generatedAt),
                        })}
                    </p>
                </header>

                <div
                    role="tablist"
                    aria-label={t('Academic reports')}
                    className="flex flex-wrap gap-1 rounded-lg border bg-card p-1 print:hidden"
                >
                    {TABS.map((key) => (
                        <button
                            key={key}
                            type="button"
                            role="tab"
                            id={`tab-${key}`}
                            aria-selected={tab === key}
                            aria-controls={`panel-${key}`}
                            onClick={() => choose(key)}
                            className={cn(
                                'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                tab === key
                                    ? 'bg-primary text-primary-foreground'
                                    : 'hover:bg-accent',
                            )}
                            data-test={`report-tab-${key}`}
                        >
                            {labels[key]}
                            {key === 'advice' && critical.length > 0 && (
                                <span
                                    className={cn(
                                        'rounded-full px-1.5 text-xs tabular-nums',
                                        tab === key
                                            ? 'bg-primary-foreground text-primary'
                                            : 'bg-destructive text-destructive-foreground',
                                    )}
                                >
                                    <span className="sr-only">
                                        {t('critical:')}{' '}
                                    </span>
                                    {critical.length}
                                </span>
                            )}
                        </button>
                    ))}
                </div>

                <section
                    role="tabpanel"
                    id={`panel-${tab}`}
                    aria-labelledby={`tab-${tab}`}
                    className="flex flex-col gap-4"
                    data-test={`report-panel-${tab}`}
                >
                    {tab === 'overview' && <Overview {...props} />}
                    {tab === 'results' && <Results {...props} />}
                    {tab === 'subjects' && <Subjects {...props} />}
                    {tab === 'teachers' && <Teachers {...props} />}
                    {tab === 'students' && <StudentsAccess {...props} />}
                    {tab === 'appeals' && <Appeals {...props} />}
                    {tab === 'marks' && <MarksHistory {...props} />}
                    {tab === 'advice' && <AdviceList advice={props.advice} />}
                </section>
            </div>
        </>
    );
}

/* ------------------------------------------------------------ pieces */

function Panel({
    title,
    description,
    children,
    test,
}: {
    title: string;
    description?: string;
    children: ReactNode;
    test?: string;
}) {
    return (
        <section
            className="flex flex-col gap-3 rounded-lg border bg-card p-4"
            data-test={test}
        >
            <div>
                <h2 className="font-semibold">{title}</h2>
                {description && (
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {children}
        </section>
    );
}

function Table({
    caption,
    head,
    children,
    test,
    wide = false,
}: {
    caption: string;
    head: string[];
    children: ReactNode;
    test?: string;
    /** Many columns: keep a readable width and scroll sideways. */
    wide?: boolean;
}) {
    return (
        <div className="overflow-x-auto rounded-md border border-grid-line bg-card">
            <table
                // Inner and outer grid lines (owner's request, 2026-11-20);
                // the outer line is the region's own border.
                className={cn(
                    'w-full border-collapse border-hidden text-sm [&_td]:border [&_td]:border-grid-line [&_th]:border [&_th]:border-grid-line',
                    wide && 'min-w-[64rem]',
                )}
                data-test={test}
            >
                <caption className="sr-only">{caption}</caption>
                <thead className="bg-table-head text-table-head-foreground">
                    <tr>
                        {head.map((cell, i) => (
                            <th
                                key={i}
                                scope="col"
                                className="px-3 py-2 text-start font-medium whitespace-nowrap"
                            >
                                {cell}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>{children}</tbody>
            </table>
        </div>
    );
}

function Cell({
    children,
    numeric = false,
    className,
}: {
    children: ReactNode;
    numeric?: boolean;
    className?: string;
}) {
    return (
        <td
            className={cn(
                'px-3 py-2 align-top',
                numeric && 'tabular-nums',
                className,
            )}
        >
            {children}
        </td>
    );
}

function place(t: Translate, stage: number, section: string, divided: boolean) {
    return placeLabel(t, stage, section, divided);
}

/* ---------------------------------------------------------- overview */

function Overview(props: Props) {
    const { t } = useTranslations();
    const { totals } = props;
    const lowest = lowestSubject(props.subjects);
    const pending = props.appeals.filter((a) => a.status === 'pending').length;

    return (
        <>
            <StatusBoard
                title={t('The students’ results')}
                test="board-results"
                items={[
                    {
                        key: 'passed',
                        label: t('Passed every subject'),
                        value: totals.passed,
                        tone: 'good',
                        icon: CheckCircle2,
                        inBar: true,
                    },
                    {
                        key: 'incomplete',
                        label: t('Incomplete results'),
                        value: totals.incomplete,
                        hint: t('a mark still missing'),
                        tone: 'wait',
                        icon: Hourglass,
                        inBar: true,
                    },
                    {
                        key: 'failed',
                        label: t('Failed'),
                        value: totals.failed,
                        tone: 'bad',
                        icon: XCircle,
                        inBar: true,
                    },
                    {
                        key: 'exempt',
                        label: t('Exempt'),
                        value: totals.exempt,
                        hint: t('of those who passed'),
                        tone: 'final',
                        icon: BadgeCheck,
                    },
                    {
                        key: 'students',
                        label: t('Students'),
                        value: totals.students,
                        hint: t(':count with every mark', {
                            count: String(totals.complete),
                        }),
                        tone: 'info',
                        icon: UsersRound,
                    },
                    {
                        key: 'pass-rate',
                        label: t('Pass rate'),
                        value: totals.passed,
                        display: percent(totals.pass_rate),
                        hint: t('of the students with every mark'),
                        tone: 'info',
                        icon: Percent,
                    },
                    {
                        key: 'appeals',
                        label: t('Appeals waiting'),
                        value: pending,
                        hint: t(':count in all', {
                            count: String(props.appeals.length),
                        }),
                        tone: 'wait',
                        icon: MessageSquareWarning,
                    },
                ]}
            />

            <div className="grid gap-4 lg:grid-cols-2">
                <Panel title={t('Pass rate by section')}>
                    <ReportBars
                        title={t('Pass rate by section')}
                        description={t(
                            'Of the students with every mark entered.',
                        )}
                        bars={sectionBars(t, props.sections)}
                        reference={props.thresholds.pass_warning}
                        referenceLabel={t(
                            'The line: :rate%, the level below which a rate needs attention.',
                            {
                                rate: String(props.thresholds.pass_warning),
                            },
                        )}
                    />
                </Panel>
                <Panel title={t('What needs attention')}>
                    {lowest && (
                        <p className="text-sm">
                            {t(
                                'The lowest pass rate is :subject (:stage): :rate%.',
                                {
                                    subject: lowest.name,
                                    stage: stageLabel(t, lowest.stage),
                                    rate: String(lowest.pass_rate),
                                },
                            )}
                        </p>
                    )}
                    <AdviceList advice={props.advice.slice(0, 5)} compact />
                </Panel>
            </div>
        </>
    );
}

function lowestSubject(subjects: SubjectRow[]): SubjectRow | null {
    const rated = subjects.filter((s) => s.pass_rate !== null);
    if (rated.length === 0) {
        return null;
    }

    return rated.reduce((low, s) =>
        (s.pass_rate ?? 0) < (low.pass_rate ?? 0) ? s : low,
    );
}

function sectionBars(t: Translate, sections: SectionRow[]): ReportBar[] {
    return sections
        .filter((row) => row.students > 0)
        .map((row) => ({
            key: `${row.stage}${row.section}`,
            label: place(t, row.stage, row.section, row.divided),
            value: row.pass_rate,
            detail: t(':passed of :complete passed', {
                passed: String(row.passed),
                complete: String(row.complete),
            }),
        }));
}

/* ----------------------------------------------------------- results */

function Results(props: Props) {
    const { t } = useTranslations();

    const outcomeCells = (row: Outcome) => (
        <>
            <Cell numeric>{row.students}</Cell>
            <Cell numeric>{row.complete}</Cell>
            <Cell numeric>{row.passed}</Cell>
            <Cell numeric>{row.failed}</Cell>
            <Cell numeric>{row.exempt}</Cell>
            <Cell numeric>{row.incomplete}</Cell>
            <Cell numeric>{percent(row.pass_rate)}</Cell>
            <Cell numeric>{percent(row.fail_rate)}</Cell>
            <Cell numeric>{percent(row.exempt_rate)}</Cell>
            <Cell numeric>{percent(row.mean)}</Cell>
            <Cell numeric>{row.failed_two_or_more}</Cell>
        </>
    );
    const head = [
        t('Stage and section'),
        t('Students'),
        t('Every mark in'),
        t('Passed'),
        t('Failed'),
        t('Exempt'),
        t('Incomplete'),
        t('Pass rate'),
        t('Fail rate'),
        t('Exemption rate'),
        t('Mean'),
        t('Failed 2+ subjects'),
    ];

    return (
        <>
            <Panel
                title={t('Passed, failed and exempt, by section and stage')}
                description={t(
                    'Rates are of the students with every mark entered; a result still missing a mark is counted as incomplete, never as failed.',
                )}
            >
                <Table
                    caption={t('Results by section and stage')}
                    head={head}
                    test="results-table"
                    wide
                >
                    {props.stages.map((stage) => (
                        <FragmentRows
                            key={stage.stage}
                            sections={props.sections.filter(
                                (row) => row.stage === stage.stage,
                            )}
                            render={(row) => (
                                <tr
                                    key={`${row.stage}${row.section}`}
                                    className="border-t"
                                >
                                    <Cell>
                                        {place(
                                            t,
                                            row.stage,
                                            row.section,
                                            row.divided,
                                        )}
                                    </Cell>
                                    {outcomeCells(row)}
                                </tr>
                            )}
                            total={
                                props.sections.filter(
                                    (row) => row.stage === stage.stage,
                                ).length > 1 ? (
                                    <tr className="border-t bg-muted font-medium">
                                        <Cell>
                                            {t(':stage, all sections', {
                                                stage: stageLabel(
                                                    t,
                                                    stage.stage,
                                                ),
                                            })}
                                        </Cell>
                                        {outcomeCells(stage)}
                                    </tr>
                                ) : null
                            }
                        />
                    ))}
                    <tr className="border-t-2 font-semibold">
                        <Cell>{t('The department')}</Cell>
                        {outcomeCells(props.totals)}
                    </tr>
                </Table>
            </Panel>

            <div className="grid gap-4 lg:grid-cols-2">
                <Panel title={t('Pass rate by stage')}>
                    <ReportBars
                        title={t('Pass rate by stage')}
                        bars={props.stages
                            .filter((stage) => stage.students > 0)
                            .map((stage) => ({
                                key: String(stage.stage),
                                label: stageLabel(t, stage.stage),
                                value: stage.pass_rate,
                                detail: t(':passed of :complete passed', {
                                    passed: String(stage.passed),
                                    complete: String(stage.complete),
                                }),
                            }))}
                        reference={props.thresholds.pass_warning}
                    />
                </Panel>
                <Panel title={t('Overall grades')}>
                    <BarChart
                        title={t('Students by overall grade')}
                        description={t(
                            'The whole department, complete results.',
                        )}
                        data={BANDS.map((band) => ({
                            key: band,
                            label: bandLabel(t, band),
                            value: props.totals.bands[band],
                        }))}
                    />
                </Panel>
            </div>

            <Panel
                title={t('The top ten of each stage')}
                description={t(
                    'By overall percentage, across the stage’s sections; equal percentages share a place.',
                )}
                test="top-ten"
            >
                <div className="grid gap-4 lg:grid-cols-3">
                    {props.top.map((stage) => (
                        <div key={stage.stage} className="flex flex-col gap-2">
                            <h3 className="font-medium">
                                {stageLabel(t, stage.stage)}
                            </h3>
                            {stage.students.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {t('No complete results yet.')}
                                </p>
                            ) : (
                                <Table
                                    caption={t('The top ten of :stage', {
                                        stage: stageLabel(t, stage.stage),
                                    })}
                                    head={[
                                        '#',
                                        t('Student'),
                                        ...(stage.divided
                                            ? [t('Section')]
                                            : []),
                                        t('Percentage'),
                                        t('Grade'),
                                    ]}
                                >
                                    {stage.students.map((row) => (
                                        <tr key={row.uuid} className="border-t">
                                            <Cell numeric>{row.rank}</Cell>
                                            <Cell>{row.name}</Cell>
                                            {stage.divided && (
                                                <Cell>{row.section}</Cell>
                                            )}
                                            <Cell numeric>
                                                {row.percentage}%
                                            </Cell>
                                            <Cell>
                                                {row.band
                                                    ? bandLabel(t, row.band)
                                                    : '—'}
                                            </Cell>
                                        </tr>
                                    ))}
                                </Table>
                            )}
                        </div>
                    ))}
                </div>
            </Panel>
        </>
    );
}

function FragmentRows({
    sections,
    render,
    total,
}: {
    sections: SectionRow[];
    render: (row: SectionRow) => ReactNode;
    total: ReactNode;
}) {
    return (
        <>
            {sections.map(render)}
            {total}
        </>
    );
}

/* ---------------------------------------------------------- subjects */

function Subjects(props: Props) {
    const { t } = useTranslations();
    const lowest = lowestSubject(props.subjects);
    const stages = [...new Set(props.subjects.map((s) => s.stage))];

    return (
        <>
            {lowest && (
                <p
                    className="flex items-start gap-2 rounded-lg border bg-card p-3 text-sm"
                    data-test="lowest-subject"
                >
                    <AlertTriangle
                        className="mt-0.5 size-4 shrink-0 text-warning-text"
                        aria-hidden
                    />
                    {t('The lowest pass rate is :subject (:stage): :rate%.', {
                        subject: lowest.name,
                        stage: stageLabel(t, lowest.stage),
                        rate: String(lowest.pass_rate),
                    })}
                </p>
            )}
            {stages.map((stage) => {
                const own = props.subjects
                    .filter((s) => s.stage === stage)
                    .sort((a, b) => (b.pass_rate ?? -1) - (a.pass_rate ?? -1));
                const low = lowestSubject(own);

                return (
                    <Panel
                        key={stage}
                        title={stageLabel(t, stage)}
                        description={
                            own[0]?.stage_mean !== null &&
                            own[0]?.stage_mean !== undefined
                                ? t(
                                      'The mean of the stage’s subjects: :mean%. Each subject is compared with it.',
                                      { mean: String(own[0].stage_mean) },
                                  )
                                : undefined
                        }
                        test="subjects-stage"
                    >
                        <ReportBars
                            title={t('Pass rate by subject')}
                            bars={own.map((s) => ({
                                key: s.uuid,
                                label: s.name,
                                value: s.pass_rate,
                                note:
                                    low && s.uuid === low.uuid && own.length > 1
                                        ? t('lowest')
                                        : undefined,
                                detail: t(':passed of :entered passed', {
                                    passed: String(s.passed),
                                    entered: String(s.entered),
                                }),
                            }))}
                            reference={props.thresholds.pass_warning}
                        />
                        <Table
                            caption={t('Subjects of :stage', {
                                stage: stageLabel(t, stage),
                            })}
                            head={[
                                t('Subject'),
                                t('Out of'),
                                t('Marks entered'),
                                t('Passed'),
                                t('Absent'),
                                t('Exempt'),
                                t('Pass rate'),
                                t('Mean'),
                                t('Highest'),
                                t('Lowest'),
                                t('Against the stage'),
                                t('Place'),
                            ]}
                            test="subjects-table"
                            wide
                        >
                            {own.map((s) => (
                                <SubjectRows key={s.uuid} subject={s} />
                            ))}
                        </Table>
                    </Panel>
                );
            })}
        </>
    );
}

function SubjectRows({ subject }: { subject: SubjectRow }) {
    const { t } = useTranslations();
    const divided = subject.sections.length > 1;

    return (
        <>
            <tr className="border-t font-medium">
                <Cell>{subject.name}</Cell>
                <Cell numeric>{subject.max}</Cell>
                <Cell numeric>
                    {t(':part of :whole', {
                        part: String(subject.entered),
                        whole: String(subject.students),
                    })}
                </Cell>
                <Cell numeric>{subject.passed}</Cell>
                <Cell numeric>{subject.absent}</Cell>
                <Cell numeric>{subject.exempt}</Cell>
                <Cell numeric>{percent(subject.pass_rate)}</Cell>
                <Cell numeric>{percent(subject.mean)}</Cell>
                <Cell numeric>{percent(subject.highest)}</Cell>
                <Cell numeric>{percent(subject.lowest)}</Cell>
                <Cell numeric>
                    {subject.difference === null
                        ? '—'
                        : `${subject.difference > 0 ? '+' : ''}${subject.difference}`}
                </Cell>
                <Cell numeric>
                    {subject.place === null
                        ? '—'
                        : t(':place of :of', {
                              place: String(subject.place),
                              of: String(subject.of),
                          })}
                </Cell>
            </tr>
            {divided &&
                subject.sections.map((row) => (
                    <tr
                        key={row.section}
                        className="border-t text-muted-foreground"
                    >
                        <Cell className="ps-6">
                            {t('Section :section', { section: row.section })}
                            <span className="block text-xs">
                                {row.teacher ?? t('No teacher')}
                            </span>
                        </Cell>
                        <Cell numeric>{subject.max}</Cell>
                        <Cell numeric>
                            {t(':part of :whole', {
                                part: String(row.entered),
                                whole: String(row.students),
                            })}
                        </Cell>
                        <Cell numeric>{row.passed}</Cell>
                        <Cell numeric>{row.absent}</Cell>
                        <Cell numeric>{row.exempt}</Cell>
                        <Cell numeric>{percent(row.pass_rate)}</Cell>
                        <Cell numeric>{percent(row.mean)}</Cell>
                        <Cell numeric>{percent(row.highest)}</Cell>
                        <Cell numeric>{percent(row.lowest)}</Cell>
                        <Cell>{''}</Cell>
                        <Cell>{''}</Cell>
                    </tr>
                ))}
        </>
    );
}

/* ---------------------------------------------------------- teachers */

function stateLabel(t: Translate, state: TeacherRow['state']): ReactNode {
    const icon = {
        approved: (
            <CheckCircle2 className="size-4 text-success-text" aria-hidden />
        ),
        complete: <Circle className="size-4 text-info-text" aria-hidden />,
        started: (
            <CircleDashed className="size-4 text-warning-text" aria-hidden />
        ),
        none: (
            <AlertOctagon
                className="size-4 text-destructive-text"
                aria-hidden
            />
        ),
        free: <Circle className="size-4 text-muted-foreground" aria-hidden />,
    }[state];
    const text = {
        approved: t('Approved every section'),
        complete: t('Every mark in, not approved'),
        started: t('Marks under way'),
        none: t('No marks yet'),
        free: t('Teaches no subject'),
    }[state];

    return (
        <span className="inline-flex items-center gap-1.5 whitespace-nowrap">
            {icon}
            {text}
        </span>
    );
}

function Teachers(props: Props) {
    const { t } = useTranslations();
    const moment = useMoment();
    const teaching = props.teachers.filter((row) => row.state !== 'free');
    const count = (state: TeacherRow['state']) =>
        teaching.filter((row) => row.state === state).length;

    return (
        <>
            <StatusBoard
                title={t('Teachers’ progress')}
                test="board-teachers"
                items={[
                    {
                        key: 'approved',
                        label: t('Approved every section'),
                        value: count('approved'),
                        tone: 'good',
                        icon: CheckCircle2,
                        inBar: true,
                    },
                    {
                        key: 'complete',
                        label: t('Every mark in, not approved'),
                        value: count('complete'),
                        tone: 'info',
                        icon: ClipboardCheck,
                        inBar: true,
                    },
                    {
                        key: 'started',
                        label: t('Marks under way'),
                        value: count('started'),
                        tone: 'wait',
                        icon: Hourglass,
                        inBar: true,
                    },
                    {
                        key: 'none',
                        label: t('No marks yet'),
                        value: count('none'),
                        tone: 'bad',
                        icon: XCircle,
                        inBar: true,
                    },
                ]}
            />

            {props.unassigned.length > 0 && (
                <Panel
                    title={t('Subject sections with no teacher')}
                    test="unassigned"
                >
                    <ul className="flex flex-col gap-1 text-sm">
                        {props.unassigned.map((row) => (
                            <li
                                key={`${row.subject}${row.stage}${row.section}`}
                                className="flex items-center gap-2"
                            >
                                <AlertOctagon
                                    className="size-4 text-destructive-text"
                                    aria-hidden
                                />
                                {row.subject} ·{' '}
                                {place(t, row.stage, row.section, row.divided)}
                            </li>
                        ))}
                    </ul>
                </Panel>
            )}

            <Panel
                title={t('Teachers who completed their marks')}
                description={t(
                    'Each subject section a teacher teaches: marks entered of the students, and when the teacher approved them.',
                )}
            >
                <Table
                    caption={t('Teachers’ progress')}
                    head={[
                        t('Teacher'),
                        t('State'),
                        t('Subject sections'),
                        t('Sign-ins'),
                        t('Last sign-in'),
                    ]}
                    test="teachers-table"
                >
                    {teaching.map((row) => (
                        <tr key={row.uuid} className="border-t">
                            <Cell>
                                <span className="font-medium">{row.name}</span>
                                {row.specialty && (
                                    <span className="block text-xs text-muted-foreground">
                                        {row.specialty}
                                    </span>
                                )}
                            </Cell>
                            <Cell>{stateLabel(t, row.state)}</Cell>
                            <Cell>
                                <ul className="flex flex-col gap-0.5">
                                    {row.assignments.map((one) => (
                                        <li
                                            key={`${one.subject}${one.stage}${one.section}`}
                                            className="text-xs"
                                        >
                                            {one.subject} ·{' '}
                                            {place(
                                                t,
                                                one.stage,
                                                one.section,
                                                one.divided,
                                            )}{' '}
                                            ·{' '}
                                            <span className="tabular-nums">
                                                {one.entered}/{one.students}
                                            </span>
                                            {one.approved_at && (
                                                <span className="text-success-text">
                                                    {' '}
                                                    ·{' '}
                                                    {t('approved :date', {
                                                        date: moment(
                                                            one.approved_at,
                                                        ),
                                                    })}
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </Cell>
                            <Cell numeric>{row.logins}</Cell>
                            <Cell>
                                {row.last_login_at
                                    ? moment(row.last_login_at)
                                    : t('Never')}
                            </Cell>
                        </tr>
                    ))}
                </Table>
            </Panel>
        </>
    );
}

/* ---------------------------------------------------------- students */

function StudentsAccess(props: Props) {
    const { t, locale } = useTranslations();
    const moment = useMoment();
    const [search, setSearch] = useState('');
    const signedIn = props.students.filter((row) => row.logins > 0).length;
    const viewed = props.students.filter((row) => row.views > 0).length;
    const viewedSignedIn = props.students.filter(
        (row) => row.views > 0 && row.logins > 0,
    ).length;
    const viewedOnly = viewed - viewedSignedIn;
    const shown = useMemo(() => {
        const needle = search.trim();

        return needle === ''
            ? props.students
            : props.students.filter(
                  (row) =>
                      row.name.includes(needle) ||
                      (row.number ?? '').includes(needle),
              );
    }, [search, props.students]);
    const dayFormat = new Intl.DateTimeFormat(locale === 'ar' ? 'ar' : 'en', {
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    });
    const days = props.activity.days.map((day) => ({
        ...day,
        label: dayFormat.format(new Date(`${day.day}T00:00:00Z`)),
    }));
    const every = Math.max(1, Math.ceil(days.length / 10));

    return (
        <>
            <StatusBoard
                title={t('Students’ access to their results')}
                test="board-access"
                items={[
                    {
                        key: 'viewed',
                        label: t('Looked at their result'),
                        value: viewed,
                        tone: 'good',
                        icon: Eye,
                        inBar: true,
                    },
                    {
                        key: 'signed-in',
                        label: t('Signed in, not looked yet'),
                        value: signedIn - viewedSignedIn,
                        tone: 'wait',
                        icon: LogIn,
                        inBar: true,
                    },
                    {
                        key: 'never',
                        label: t('Never signed in'),
                        value: props.students.length - signedIn - viewedOnly,
                        tone: 'bad',
                        icon: XCircle,
                        inBar: true,
                    },
                    {
                        key: 'student-logins',
                        label: t('Student sign-ins'),
                        value: props.activity.student_logins,
                        hint: t('in the last :days days', {
                            days: String(days.length),
                        }),
                        tone: 'info',
                        icon: UsersRound,
                    },
                    {
                        key: 'teacher-logins',
                        label: t('Teacher sign-ins'),
                        value: props.activity.teacher_logins,
                        hint: t('in the last :days days', {
                            days: String(days.length),
                        }),
                        tone: 'info',
                        icon: UserRound,
                    },
                ]}
            />

            <div className="grid gap-4">
                <Panel title={t('Student sign-ins per day')}>
                    <BarChart
                        title={t('Student sign-ins per day')}
                        data={days.map((day) => ({
                            key: day.day,
                            label: day.label,
                            value: day.student_logins,
                        }))}
                        labelEvery={every}
                    />
                </Panel>
                <Panel title={t('Views of results per day')}>
                    <BarChart
                        title={t('Views of results per day')}
                        data={days.map((day) => ({
                            key: day.day,
                            label: day.label,
                            value: day.views,
                        }))}
                        labelEvery={every}
                    />
                </Panel>
                <Panel title={t('Teacher sign-ins per day')}>
                    <BarChart
                        title={t('Teacher sign-ins per day')}
                        data={days.map((day) => ({
                            key: day.day,
                            label: day.label,
                            value: day.teacher_logins,
                        }))}
                        labelEvery={every}
                    />
                </Panel>
            </div>

            <Panel
                title={t('Each student’s sign-ins and views')}
                description={t(
                    'A view is counted once in ten minutes, as the student’s own page records it.',
                )}
            >
                <Input
                    type="search"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder={t('Search by name or number')}
                    aria-label={t('Search by name or number')}
                    className="max-w-sm"
                    data-test="students-search"
                />
                <Table
                    caption={t('Students’ sign-ins and views')}
                    head={[
                        t('Student'),
                        t('Stage and section'),
                        t('Sign-ins'),
                        t('Last sign-in'),
                        t('Views of the result'),
                        t('Last view'),
                    ]}
                    test="students-table"
                >
                    {shown.map((row) => (
                        <tr key={row.uuid} className="border-t">
                            <Cell>
                                {row.name}
                                {row.blocked && (
                                    <span className="ms-2 text-xs text-destructive-text">
                                        {t('blocked')}
                                    </span>
                                )}
                            </Cell>
                            <Cell>
                                {place(t, row.stage, row.section, row.divided)}
                            </Cell>
                            <Cell numeric>{row.logins}</Cell>
                            <Cell>
                                {row.last_login_at
                                    ? moment(row.last_login_at)
                                    : t('Never')}
                            </Cell>
                            <Cell numeric>{row.views}</Cell>
                            <Cell>
                                {row.last_view_at
                                    ? moment(row.last_view_at)
                                    : t('Never')}
                            </Cell>
                        </tr>
                    ))}
                </Table>
            </Panel>
        </>
    );
}

/* ----------------------------------------------------------- appeals */

function Appeals(props: Props) {
    const { t } = useTranslations();
    const moment = useMoment();
    const count = (status: AppealRow['status']) =>
        props.appeals.filter((row) => row.status === status).length;
    const answered = count('accepted') + count('rejected');
    const bySubject = Object.entries(
        props.appeals.reduce<Record<string, number>>((all, row) => {
            all[row.subject] = (all[row.subject] ?? 0) + 1;

            return all;
        }, {}),
    ).sort((a, b) => b[1] - a[1]);
    const status = {
        pending: t('Waiting'),
        accepted: t('Accepted'),
        rejected: t('Rejected'),
    };

    return (
        <>
            <StatusBoard
                title={t('Appeals and their outcome')}
                test="board-appeals"
                items={[
                    {
                        key: 'accepted',
                        label: t('Accepted'),
                        value: count('accepted'),
                        tone: 'good',
                        icon: CheckCircle2,
                        inBar: true,
                    },
                    {
                        key: 'pending',
                        label: t('Waiting'),
                        value: count('pending'),
                        tone: 'wait',
                        icon: Search,
                        inBar: true,
                    },
                    {
                        key: 'rejected',
                        label: t('Rejected'),
                        value: count('rejected'),
                        tone: 'bad',
                        icon: XCircle,
                        inBar: true,
                    },
                    {
                        key: 'acceptance',
                        label: t('Acceptance rate'),
                        value: count('accepted'),
                        display:
                            answered === 0
                                ? '—'
                                : `${Math.round((count('accepted') * 1000) / answered) / 10}%`,
                        hint: t('of the answered appeals'),
                        tone: 'info',
                        icon: Percent,
                    },
                ]}
            />

            {bySubject.length > 0 && (
                <Panel title={t('Appeals by subject')}>
                    <BarChart
                        title={t('Appeals by subject')}
                        data={bySubject.map(([subject, value]) => ({
                            key: subject,
                            label: subject,
                            value,
                        }))}
                    />
                </Panel>
            )}

            <Panel
                title={t('Every appeal')}
                description={t(
                    'Who appealed which subject, the subject’s teacher in the student’s section, the answer and who gave it.',
                )}
            >
                {props.appeals.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('No appeals this period.')}
                    </p>
                ) : (
                    <Table
                        caption={t('Every appeal')}
                        head={[
                            t('Student'),
                            t('Subject'),
                            t('Teacher'),
                            t('Reason'),
                            t('State'),
                            t('Answer'),
                            t('Answered by'),
                            t('Dates'),
                        ]}
                        test="appeals-table"
                        wide
                    >
                        {props.appeals.map((row) => (
                            <tr key={row.uuid} className="border-t">
                                <Cell>
                                    {row.student}
                                    <span className="block text-xs text-muted-foreground">
                                        {place(
                                            t,
                                            row.stage,
                                            row.section,
                                            row.divided,
                                        )}
                                    </span>
                                </Cell>
                                <Cell>{row.subject}</Cell>
                                <Cell>{row.teacher ?? t('No teacher')}</Cell>
                                <Cell className="max-w-64">{row.reason}</Cell>
                                <Cell>
                                    <span className="inline-flex items-center gap-1.5 whitespace-nowrap">
                                        {row.status === 'accepted' ? (
                                            <CheckCircle2
                                                className="size-4 text-success-text"
                                                aria-hidden
                                            />
                                        ) : row.status === 'rejected' ? (
                                            <AlertOctagon
                                                className="size-4 text-destructive-text"
                                                aria-hidden
                                            />
                                        ) : (
                                            <CircleDashed
                                                className="size-4 text-warning-text"
                                                aria-hidden
                                            />
                                        )}
                                        {status[row.status]}
                                    </span>
                                </Cell>
                                <Cell className="max-w-64">
                                    {row.response ?? '—'}
                                </Cell>
                                <Cell>
                                    {row.decided_by
                                        ? `${row.decided_by} (${row.decided_by_teacher ? t('teacher') : t('administration')})`
                                        : '—'}
                                </Cell>
                                <Cell className="text-xs whitespace-nowrap">
                                    {moment(row.created_at)}
                                    {row.decided_at && (
                                        <span className="block text-muted-foreground">
                                            {t('answered :date', {
                                                date: moment(row.decided_at),
                                            })}
                                        </span>
                                    )}
                                </Cell>
                            </tr>
                        ))}
                    </Table>
                )}
            </Panel>
        </>
    );
}

/* ------------------------------------------------------ marks history */

function MarksHistory(props: Props) {
    const { t } = useTranslations();
    const moment = useMoment();

    return (
        <Panel
            title={t('When the marks were entered')}
            description={t(
                'Each subject section: the first mark, the last change, and how many changes the audit trail holds. Corrections are changes after a mark’s first entry.',
            )}
        >
            {props.marks.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    {t('No marks yet.')}
                </p>
            ) : (
                <Table
                    caption={t('When the marks were entered')}
                    head={[
                        t('Subject'),
                        t('Stage and section'),
                        t('Marks'),
                        t('First entered'),
                        t('Last changed'),
                        t('Changes'),
                        t('Corrections'),
                        t('By the administration'),
                    ]}
                    test="marks-table"
                >
                    {props.marks.map((row) => (
                        <tr
                            key={`${row.subject}${row.stage}${row.section}`}
                            className="border-t"
                        >
                            <Cell>{row.subject}</Cell>
                            <Cell>
                                {place(t, row.stage, row.section, row.divided)}
                            </Cell>
                            <Cell numeric>{row.marks}</Cell>
                            <Cell className="text-xs whitespace-nowrap">
                                {moment(row.first_at)}
                            </Cell>
                            <Cell className="text-xs whitespace-nowrap">
                                {moment(row.last_at)}
                            </Cell>
                            <Cell numeric>{row.changes}</Cell>
                            <Cell
                                numeric
                                className={
                                    row.corrections > 0
                                        ? 'font-medium text-warning-text'
                                        : undefined
                                }
                            >
                                {row.corrections}
                            </Cell>
                            <Cell numeric>{row.by_owner}</Cell>
                        </tr>
                    ))}
                </Table>
            )}
        </Panel>
    );
}

/* ---------------------------------------------------------- advice */

const PROPOSALS = [
    {
        title: 'An early-warning list',
        description:
            'Flag, during the term, each student failing two subjects or absent often, and give them an adviser before the final exams.',
    },
    {
        title: 'Every month side by side',
        description:
            'When the second month is added, compare each subject and section with the month before, to see who improved and who fell back.',
    },
    {
        title: 'Second marking of a sample',
        description:
            'In a subject with a low pass rate or many appeals, have a second teacher re-mark a tenth of the papers before publishing.',
    },
    {
        title: 'One exam per subject across sections',
        description:
            'Sections of one subject sit the same exam with the same marking scheme, so their results can be compared fairly.',
    },
    {
        title: 'Mark distribution per subject',
        description:
            'Show how many students scored in each tenth of the marks, to spot an exam that was too easy or too hard.',
    },
    {
        title: 'Learning outcomes',
        description:
            'Link each subject to the programme’s learning outcomes, so the results also say which outcomes the students reached, as accreditation asks.',
    },
    {
        title: 'Teachers’ own summary',
        description:
            'Give each teacher, after publication, their sections’ figures against the stage, to reflect on their teaching.',
    },
    {
        title: 'A report to download',
        description:
            'Export these reports as a signed PDF for the department council’s minutes.',
    },
];

function AdviceList({
    advice,
    compact = false,
}: {
    advice: Advice[];
    compact?: boolean;
}) {
    const { t } = useTranslations();
    const level = {
        critical: {
            icon: (
                <AlertOctagon
                    className="size-4 shrink-0 text-destructive-text"
                    aria-hidden
                />
            ),
            label: t('Urgent'),
        },
        warning: {
            icon: (
                <AlertTriangle
                    className="size-4 shrink-0 text-warning-text"
                    aria-hidden
                />
            ),
            label: t('Follow up'),
        },
        info: {
            icon: (
                <Info className="size-4 shrink-0 text-info-text" aria-hidden />
            ),
            label: t('Worth knowing'),
        },
    };

    const list = (
        <ul className="flex flex-col gap-2" data-test="advice-list">
            {advice.map((one, i) => (
                <li
                    key={i}
                    className="flex gap-3 rounded-md border bg-card p-3 text-sm"
                    data-test={`advice-${one.level}`}
                >
                    <span className="mt-0.5">{level[one.level].icon}</span>
                    <div className="flex flex-col gap-1">
                        <span>
                            <span className="font-semibold">
                                {level[one.level].label}:
                            </span>{' '}
                            {one.text}
                        </span>
                        <span className="text-muted-foreground">
                            {t('Recommendation:')} {one.action}
                        </span>
                    </div>
                </li>
            ))}
        </ul>
    );

    if (compact) {
        return list;
    }

    return (
        <>
            <Panel
                title={t('Recommendations to the administration')}
                description={t(
                    'Drawn from this period’s figures by fixed rules, most urgent first. Each names the figure behind it.',
                )}
            >
                {list}
            </Panel>
            <Panel
                title={t('Academic proposals')}
                description={t(
                    'Ideas to make the programme stronger. Proposed, not built: each needs the department’s approval first.',
                )}
                test="proposals"
            >
                <ul className="grid gap-3 md:grid-cols-2">
                    {PROPOSALS.map((one) => (
                        <li
                            key={one.title}
                            className="flex gap-3 rounded-md border bg-card p-3 text-sm"
                        >
                            <Lightbulb
                                className="mt-0.5 size-4 shrink-0 text-info-text"
                                aria-hidden
                            />
                            <div className="flex flex-col gap-1">
                                <span className="font-semibold">
                                    {t(one.title)}
                                </span>
                                <span className="text-muted-foreground">
                                    {t(one.description)}
                                </span>
                            </div>
                        </li>
                    ))}
                </ul>
            </Panel>
        </>
    );
}
