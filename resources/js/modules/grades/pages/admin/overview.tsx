import { Head, Link, router } from '@inertiajs/react';
import { Lock, LockOpen } from 'lucide-react';
import { lazy, Suspense } from 'react';
import GradesAdminController from '@/actions/App/Modules/Grades/Http/GradesAdminController';
import ResultsController from '@/actions/App/Modules/Grades/Http/ResultsController';
import PageToolbar from '@/components/page-toolbar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import type { AppealCounts } from '../../components/appeals-admin-panel';
import type { AccessBoardProps } from '../../components/access-panel';
import CopyLink from '../../components/copy-link';
import type { PublicationStage } from '../../components/publication-panel';
import type { ResultSettings } from '../../components/result-settings-panel';
import {
    ProgressOverview,
    SettingsLanding,
    useSettingsTabs,
} from '../../components/settings-tabs';
import type { SettingsTab } from '../../components/settings-tabs';
import { sectionLabel, stageLabel } from '../../place';

// Each panel opens from its own settings card: loaded then, to keep the
// page within the bundle budget (ADR-0024).
const AccessPanel = lazy(() => import('../../components/access-panel'));
const AppealsAdminPanel = lazy(
    () => import('../../components/appeals-admin-panel'),
);
const PublicationPanel = lazy(
    () => import('../../components/publication-panel'),
);
const ResultSettingsPanel = lazy(
    () => import('../../components/result-settings-panel'),
);

type Stage = PublicationStage;

/**
 * The owner's settings (ADR-0034, called "Control panel" since 2026-11-20), in
 * tabs, each a page of its own (owner's request, 2026-11-19): mark entry
 * and publication, the links, teachers' and students' sign-in rules,
 * teachers' permissions, the result card, what teachers see, and each
 * stage's subjects and teachers. /admin shows one coloured card per tab;
 * inside a tab, Close (beside previous, next and home) returns to them.
 */
export default function GradesOverview({
    tab = null,
    window,
    stages,
    teacherLink,
    studentLink,
    adminLink,
    canManage,
    access,
    resultSettings,
    appealCounts = null,
}: {
    /** The open tab; null: the list of tabs. */
    tab?: SettingsTab | null;
    window: {
        open: boolean;
        published: boolean;
        opened_at: string | null;
        closed_at: string | null;
        /** Sections published on their own (ADR-0042): "stage|section". */
        sections_published: string[];
    };
    stages: Stage[];
    teacherLink: string;
    studentLink: string;
    adminLink: string;
    canManage: boolean;
    /** Sign-ins and mark editing (ADR-0037); only for grades.manage. */
    access: AccessBoardProps | null;
    /** What a result says and shows (ADR-0039); only for grades.manage. */
    resultSettings: ResultSettings | null;
    /** Appeals by status (appeals tab); only for grades.manage. */
    appealCounts?: AppealCounts | null;
}) {
    const { t } = useTranslations();
    const put = (url: string, data: Record<string, string | boolean>) =>
        router.put(url, data, { preserveScroll: true });
    const subjectCount = stages.reduce(
        (sum, stage) => sum + stage.subjects.length,
        0,
    );
    const studentTotal = stages.reduce(
        (sum, stage) =>
            sum +
            Object.values(stage.students).reduce((a, b) => a + (b ?? 0), 0),
        0,
    );
    const appealTotal = appealCounts
        ? appealCounts.pending + appealCounts.accepted + appealCounts.rejected
        : 0;
    // Each card's progress (owner's request, 2026-11-20), from what the
    // page already has: done of due.
    let marksDue = 0;
    let marksIn = 0;
    let sectionsDue = 0;
    let sectionsApproved = 0;
    let sectionsTaught = 0;
    let placesDue = 0;
    for (const stage of stages) {
        const places = Object.entries(stage.students);
        placesDue += places.filter(
            ([, students]) => (students ?? 0) > 0,
        ).length;
        for (const subject of stage.subjects) {
            for (const [section, students] of places) {
                sectionsDue += 1;
                marksDue += students ?? 0;
                marksIn += Math.min(
                    subject.sections[section] ?? 0,
                    students ?? 0,
                );
                if (subject.approved[section]) {
                    sectionsApproved += 1;
                }
                if (subject.teachers[section]) {
                    sectionsTaught += 1;
                }
            }
        }
    }
    const placesPublished = window.published
        ? placesDue
        : window.sections_published.length;
    const teachersIn =
        access?.teachers.filter((teacher) => teacher.logins > 0).length ?? 0;
    const teacherCount = access?.teachers.length ?? 0;
    const appealsAnswered = appealCounts
        ? appealCounts.accepted + appealCounts.rejected
        : 0;

    // The large number on each card (owner's picture, 2026-11-20).
    const tabs = useSettingsTabs(canManage, {
        window: {
            count: window.published
                ? t('Published')
                : window.open
                  ? t('Open')
                  : t('Closed'),
            progress: {
                done: marksIn,
                of: marksDue,
                label: t('Marks entered'),
            },
            summary: `${window.open ? t('Mark entry is open.') : t('Mark entry is closed.')} ${window.published ? t('Results are published to the students.') : t('Results are not published to the students.')}`,
        },
        links: {
            summary: t('The addresses to give teachers and students.'),
            count: 3,
            progress: access
                ? {
                      done: teachersIn + access.students.signed_in,
                      of: teacherCount + access.students.total,
                      label: t('Signed in through the links'),
                  }
                : undefined,
        },
        teachers: {
            count: access?.teachers.length,
            progress: access
                ? {
                      done: teachersIn,
                      of: teacherCount,
                      label: t('Teachers signed in'),
                  }
                : undefined,
            summary: t(':count refused teacher sign-ins in 24 hours.', {
                count: String(access?.failed.teachers ?? 0),
            }),
        },
        students: {
            count: access?.students.total ?? studentTotal,
            progress: access
                ? {
                      done: access.students.signed_in,
                      of: access.students.total,
                      label: t('Students signed in'),
                  }
                : undefined,
            summary: t(':signed of :total students signed in.', {
                signed: String(access?.students.signed_in ?? 0),
                total: String(access?.students.total ?? 0),
            }),
        },
        appeals: {
            count: appealCounts?.pending ?? 0,
            progress: appealCounts
                ? {
                      done: appealsAnswered,
                      of: appealTotal,
                      label: t('Appeals answered'),
                  }
                : undefined,
            summary: !resultSettings?.options.appeals
                ? t('Appeals are off.')
                : resultSettings.teacher_appeal_replies
                  ? t(':total appeals; the subject teacher answers.', {
                        total: String(appealTotal),
                    })
                  : t(':total appeals; the administration answers.', {
                        total: String(appealTotal),
                    }),
            attention: access?.appeals.length,
        },
        permissions: {
            count: access?.requests.length ?? 0,
            progress: {
                done: sectionsApproved,
                of: sectionsDue,
                label: t('Sections approved by their teacher'),
            },
            summary: t(
                ':teachers teachers; :requests requests to change marks waiting.',
                {
                    teachers: String(access?.teachers.length ?? 0),
                    requests: String(access?.requests.length ?? 0),
                },
            ),
            attention: access?.requests.length,
        },
        card: {
            count: `${resultSettings?.pass_percent ?? 50}%`,
            progress: {
                done: placesPublished,
                of: placesDue,
                label: t('Sections with published results'),
            },
            summary: t(
                'Pass at :percent%; grades, exemption and colours of marks.',
                {
                    percent: String(resultSettings?.pass_percent ?? 50),
                },
            ),
        },
        'teacher-view': {
            summary:
                resultSettings?.teacher_results === 'always'
                    ? t('Always, while marks come in too')
                    : resultSettings?.teacher_results === 'published'
                      ? t('Once published to the students')
                      : t('Teachers do not read the results.'),
            // A department-wide setting: every teacher or none.
            progress: resultSettings
                ? {
                      done:
                          resultSettings.teacher_results === 'off'
                              ? 0
                              : teacherCount,
                      of: teacherCount,
                      label: t('Teachers who may read the results'),
                  }
                : undefined,
        },
        subjects: {
            count: subjectCount,
            progress: {
                done: sectionsTaught,
                of: sectionsDue,
                label: t('Subject sections with a teacher'),
            },
            summary: t(':count subjects across the stages.', {
                count: String(subjectCount),
            }),
        },
    });
    const open = tabs.find((item) => item.key === tab) ?? null;

    return (
        <>
            <Head
                title={
                    open
                        ? `${open.label} · ${t('Control panel')}`
                        : t('Control panel')
                }
            />
            <PageToolbar title={open ? open.label : t('Control panel')} />
            <div className="flex flex-col gap-6 p-4">
                {open === null && <ProgressOverview tabs={tabs} />}
                {open === null && <SettingsLanding tabs={tabs} />}

                {open?.key === 'window' && (
                    <div className="mx-auto flex w-full max-w-5xl flex-col gap-4">
                        <section
                            className="flex flex-wrap items-center gap-3 rounded-lg border bg-card p-5"
                            data-test="entry-window"
                        >
                            {window.open ? (
                                <LockOpen
                                    className="size-5 text-success-text"
                                    aria-hidden
                                />
                            ) : (
                                <Lock
                                    className="size-5 text-muted-foreground"
                                    aria-hidden
                                />
                            )}
                            <div className="flex-1">
                                <h2 className="font-semibold">
                                    {t('Open mark entry to the teaching staff')}
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    {window.open
                                        ? t('Mark entry is open.')
                                        : t('Mark entry is closed.')}
                                </p>
                                {canManage &&
                                    !window.open &&
                                    (window.published ||
                                        window.sections_published.length >
                                            0) && (
                                        <p
                                            className="text-sm text-muted-foreground"
                                            data-test="open-window-hint"
                                        >
                                            {t(
                                                'Hide the published results before opening mark entry again.',
                                            )}
                                        </p>
                                    )}
                            </div>
                            {canManage &&
                                (window.open ? (
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            put(
                                                GradesAdminController.window.url(),
                                                { status: 'closed' },
                                            )
                                        }
                                        data-test="close-window"
                                    >
                                        {t('Close mark entry')}
                                    </Button>
                                ) : (
                                    <Button
                                        // A section published on its own also
                                        // keeps entry closed (ChangeEntryWindow).
                                        disabled={
                                            window.published ||
                                            window.sections_published.length > 0
                                        }
                                        onClick={() =>
                                            put(
                                                GradesAdminController.window.url(),
                                                { status: 'open' },
                                            )
                                        }
                                        data-test="open-window"
                                    >
                                        {t('Open mark entry')}
                                    </Button>
                                ))}
                        </section>

                        <Suspense fallback={null}>
                            <PublicationPanel
                                window={window}
                                stages={stages}
                                canManage={canManage}
                            />
                        </Suspense>
                    </div>
                )}

                {open?.key === 'links' && (
                    <section className="grid gap-6 rounded-lg border bg-card p-4 md:grid-cols-2 xl:grid-cols-3">
                        <CopyLink
                            id="teacher-link"
                            href={teacherLink}
                            label={t('Link for the teachers')}
                        />
                        <CopyLink
                            id="student-link"
                            href={studentLink}
                            label={t('Link for the students')}
                        />
                        <CopyLink
                            id="admin-link"
                            href={adminLink}
                            label={t('Link for the administration')}
                        />
                    </section>
                )}

                {access &&
                    (open?.key === 'teachers' ||
                        open?.key === 'students' ||
                        open?.key === 'permissions') && (
                        <Suspense fallback={null}>
                            <AccessPanel
                                access={access}
                                studentView={resultSettings}
                                part={open.key}
                            />
                        </Suspense>
                    )}

                {resultSettings &&
                    (open?.key === 'card' || open?.key === 'teacher-view') && (
                        <Suspense fallback={null}>
                            <ResultSettingsPanel
                                settings={resultSettings}
                                part={open.key === 'card' ? 'card' : 'teachers'}
                            />
                        </Suspense>
                    )}

                {resultSettings && appealCounts && open?.key === 'appeals' && (
                    <Suspense fallback={null}>
                        <AppealsAdminPanel
                            settings={resultSettings}
                            counts={appealCounts}
                            appeals={access?.appeals ?? []}
                        />
                    </Suspense>
                )}

                {open?.key === 'subjects' &&
                    stages.map((stage) => {
                        const sections = Object.keys(stage.students);

                        return (
                            <section
                                key={stage.stage}
                                className="rounded-lg border bg-card"
                                aria-labelledby={`stage-${stage.stage}`}
                            >
                                <header className="flex flex-wrap items-baseline gap-x-4 gap-y-1 border-b p-4">
                                    <h2
                                        id={`stage-${stage.stage}`}
                                        className="font-semibold"
                                    >
                                        {stageLabel(t, stage.stage)}
                                    </h2>
                                    <span className="text-sm text-muted-foreground">
                                        {sections
                                            .map((section) =>
                                                stage.divided
                                                    ? t(
                                                          ':label: :count students',
                                                          {
                                                              label: sectionLabel(
                                                                  t,
                                                                  section,
                                                                  true,
                                                              ),
                                                              count: String(
                                                                  stage
                                                                      .students[
                                                                      section
                                                                  ] ?? 0,
                                                              ),
                                                          },
                                                      )
                                                    : t(':count students', {
                                                          count: String(
                                                              stage.students[
                                                                  section
                                                              ] ?? 0,
                                                          ),
                                                      }),
                                            )
                                            .join(' · ')}
                                    </span>
                                </header>

                                <div className="overflow-x-auto p-4">
                                    <table className="w-full text-sm">
                                        <caption className="sr-only">
                                            {stageLabel(t, stage.stage)}
                                        </caption>
                                        <thead>
                                            <tr className="text-muted-foreground">
                                                <th
                                                    scope="col"
                                                    className="p-2 text-start font-medium"
                                                >
                                                    {t('Subject')}
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="p-2 text-start font-medium"
                                                >
                                                    {t('Teacher')}
                                                </th>
                                                {sections.map((section) => (
                                                    <th
                                                        key={section}
                                                        scope="col"
                                                        className="p-2 text-start font-medium"
                                                    >
                                                        {sectionLabel(
                                                            t,
                                                            section,
                                                            stage.divided,
                                                        )}
                                                    </th>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {stage.subjects.map((subject) => (
                                                <tr
                                                    key={subject.uuid}
                                                    className="border-t"
                                                >
                                                    <th
                                                        scope="row"
                                                        className="p-2 text-start font-medium"
                                                    >
                                                        <bdi>
                                                            {subject.name}
                                                        </bdi>
                                                    </th>
                                                    <td className="p-2">
                                                        {/* One line per section:
                                                        each may have its own
                                                        teacher. */}
                                                        {sections.map(
                                                            (section) => {
                                                                const name =
                                                                    subject
                                                                        .teachers[
                                                                        section
                                                                    ] ?? null;

                                                                return (
                                                                    <span
                                                                        key={
                                                                            section
                                                                        }
                                                                        className="block"
                                                                    >
                                                                        {stage.divided && (
                                                                            <span className="text-muted-foreground">
                                                                                {`${section}: `}
                                                                            </span>
                                                                        )}
                                                                        {name ? (
                                                                            <bdi>
                                                                                {
                                                                                    name
                                                                                }
                                                                            </bdi>
                                                                        ) : (
                                                                            <Badge variant="destructive">
                                                                                {t(
                                                                                    'No teacher',
                                                                                )}
                                                                            </Badge>
                                                                        )}
                                                                    </span>
                                                                );
                                                            },
                                                        )}
                                                    </td>
                                                    {sections.map((section) => {
                                                        const total =
                                                            stage.students[
                                                                section
                                                            ] ?? 0;
                                                        const done =
                                                            subject.sections[
                                                                section
                                                            ] ?? 0;
                                                        const label = t(
                                                            ':entered of :students entered',
                                                            {
                                                                entered:
                                                                    String(
                                                                        done,
                                                                    ),
                                                                students:
                                                                    String(
                                                                        total,
                                                                    ),
                                                            },
                                                        );

                                                        return (
                                                            <td
                                                                key={section}
                                                                className="p-2 tabular-nums"
                                                            >
                                                                {canManage ? (
                                                                    <Link
                                                                        prefetch
                                                                        href={ResultsController.sheet.url(
                                                                            {
                                                                                subject:
                                                                                    subject.uuid,
                                                                                section,
                                                                            },
                                                                        )}
                                                                        className={
                                                                            total >
                                                                                0 &&
                                                                            done ===
                                                                                total
                                                                                ? 'text-success-text underline-offset-4 hover:underline'
                                                                                : 'underline-offset-4 hover:underline'
                                                                        }
                                                                    >
                                                                        {label}
                                                                    </Link>
                                                                ) : (
                                                                    label
                                                                )}
                                                                {subject
                                                                    .approved[
                                                                    section
                                                                ] && (
                                                                    <Badge
                                                                        variant="secondary"
                                                                        className="ms-2"
                                                                        data-test="section-approved"
                                                                    >
                                                                        {t(
                                                                            'Approved',
                                                                        )}
                                                                    </Badge>
                                                                )}
                                                            </td>
                                                        );
                                                    })}
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        );
                    })}
            </div>
        </>
    );
}
