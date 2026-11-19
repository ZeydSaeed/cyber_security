import { Head, Link, router } from '@inertiajs/react';
import { Eye, EyeOff, Lock, LockOpen } from 'lucide-react';
import GradesAdminController from '@/actions/App/Modules/Grades/Http/GradesAdminController';
import ResultsController from '@/actions/App/Modules/Grades/Http/ResultsController';
import PageToolbar from '@/components/page-toolbar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import AccessPanel from '../../components/access-panel';
import type { AccessBoardProps } from '../../components/access-panel';
import CopyLink from '../../components/copy-link';
import ResultSettingsPanel from '../../components/result-settings-panel';
import type { ResultSettings } from '../../components/result-settings-panel';
import {
    SettingsClose,
    SettingsLanding,
    useSettingsTabs,
} from '../../components/settings-tabs';
import type { SettingsTab } from '../../components/settings-tabs';
import { placeLabel, sectionLabel, stageLabel } from '../../place';

type Stage = {
    stage: number;
    divided: boolean;
    students: Record<string, number>;
    subjects: {
        uuid: string;
        name: string;
        /** Each section's teacher's name (null: none yet). */
        teachers: Record<string, string | null>;
        sections: Record<string, number>;
        /** Whether each section's teacher approved the marks (ADR-0041). */
        approved: Record<string, boolean>;
    }[];
};

/**
 * The owner's settings (ADR-0034, called "Settings" since 2026-11-19), in
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
}) {
    const { t } = useTranslations();
    const put = (url: string, data: Record<string, string | boolean>) =>
        router.put(url, data, { preserveScroll: true });
    const subjectCount = stages.reduce(
        (sum, stage) => sum + stage.subjects.length,
        0,
    );
    const tabs = useSettingsTabs(canManage, {
        window: {
            summary: `${window.open ? t('Mark entry is open.') : t('Mark entry is closed.')} ${window.published ? t('Results are published to the students.') : t('Results are not published to the students.')}`,
        },
        links: { summary: t('The addresses to give teachers and students.') },
        teachers: {
            summary: t(':count refused teacher sign-ins in 24 hours.', {
                count: String(access?.failed.teachers ?? 0),
            }),
        },
        students: {
            summary: t(
                ':signed of :total students signed in; :appeals appeals waiting.',
                {
                    signed: String(access?.students.signed_in ?? 0),
                    total: String(access?.students.total ?? 0),
                    appeals: String(access?.appeals.length ?? 0),
                },
            ),
            attention: access?.appeals.length,
        },
        permissions: {
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
        },
        subjects: {
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
                    open ? `${open.label} · ${t('Settings')}` : t('Settings')
                }
            />
            <PageToolbar title={open ? open.label : t('Settings')} />
            {open !== null && <SettingsClose />}
            <div className="flex flex-col gap-6 p-4">
                {open === null && <SettingsLanding tabs={tabs} />}

                {open?.key === 'window' && (
                    <section
                        className="grid gap-4 rounded-lg border bg-card p-4 md:grid-cols-2"
                        data-test="entry-window"
                    >
                        <div className="flex flex-col gap-3">
                            <div className="flex items-center gap-3">
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
                                <p className="font-medium">
                                    {window.open
                                        ? t('Mark entry is open.')
                                        : t('Mark entry is closed.')}
                                </p>
                            </div>
                            {canManage &&
                                (window.open ? (
                                    <Button
                                        variant="outline"
                                        className="self-start"
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
                                        className="self-start"
                                        disabled={window.published}
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
                        </div>

                        <div
                            className="flex flex-col gap-3"
                            data-test="publication"
                        >
                            <div className="flex items-center gap-3">
                                {window.published ? (
                                    <Eye
                                        className="size-5 text-success-text"
                                        aria-hidden
                                    />
                                ) : (
                                    <EyeOff
                                        className="size-5 text-muted-foreground"
                                        aria-hidden
                                    />
                                )}
                                <div>
                                    <p className="font-medium">
                                        {window.published
                                            ? t(
                                                  'Results are published to the students.',
                                              )
                                            : t(
                                                  'Results are not published to the students.',
                                              )}
                                    </p>
                                    {window.open && (
                                        <p className="text-sm text-muted-foreground">
                                            {t(
                                                'Close mark entry before publishing.',
                                            )}
                                        </p>
                                    )}
                                </div>
                            </div>
                            {canManage &&
                                (window.published ? (
                                    <Button
                                        variant="outline"
                                        className="self-start"
                                        onClick={() =>
                                            put(
                                                GradesAdminController.publish.url(),
                                                { published: false },
                                            )
                                        }
                                        data-test="unpublish"
                                    >
                                        {t('Hide the results')}
                                    </Button>
                                ) : (
                                    <Button
                                        className="self-start"
                                        disabled={window.open}
                                        onClick={() =>
                                            put(
                                                GradesAdminController.publish.url(),
                                                { published: true },
                                            )
                                        }
                                        data-test="publish"
                                    >
                                        {t('Publish the results')}
                                    </Button>
                                ))}
                            {/* One section at a time (ADR-0042), while not all are. */}
                            {canManage && !window.published && (
                                <div
                                    className="flex flex-wrap gap-2"
                                    data-test="section-publication"
                                >
                                    {stages.flatMap((stage) =>
                                        Object.keys(stage.students).map(
                                            (section) => {
                                                const shown =
                                                    window.sections_published.includes(
                                                        `${stage.stage}|${section}`,
                                                    );

                                                return (
                                                    <Button
                                                        key={`${stage.stage}${section}`}
                                                        size="sm"
                                                        variant={
                                                            shown
                                                                ? 'default'
                                                                : 'outline'
                                                        }
                                                        disabled={
                                                            window.open &&
                                                            !shown
                                                        }
                                                        aria-pressed={shown}
                                                        onClick={() =>
                                                            put(
                                                                GradesAdminController.publishSection.url(),
                                                                {
                                                                    stage: String(
                                                                        stage.stage,
                                                                    ),
                                                                    section,
                                                                    published:
                                                                        !shown,
                                                                },
                                                            )
                                                        }
                                                        data-test="publish-section"
                                                    >
                                                        {placeLabel(
                                                            t,
                                                            stage.stage,
                                                            section,
                                                            stage.divided,
                                                        )}
                                                        {shown
                                                            ? ` · ${t('Published')}`
                                                            : ''}
                                                    </Button>
                                                );
                                            },
                                        ),
                                    )}
                                </div>
                            )}
                        </div>
                    </section>
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
                        <AccessPanel
                            access={access}
                            studentView={resultSettings}
                            part={open.key}
                        />
                    )}

                {resultSettings &&
                    (open?.key === 'card' || open?.key === 'teacher-view') && (
                        <ResultSettingsPanel
                            settings={resultSettings}
                            part={open.key === 'card' ? 'card' : 'teachers'}
                        />
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
