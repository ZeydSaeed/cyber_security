import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Eye, EyeOff, Lock, LockOpen } from 'lucide-react';
import GradesAdminController from '@/actions/App/Modules/Grades/Http/GradesAdminController';
import ResultsController from '@/actions/App/Modules/Grades/Http/ResultsController';
import StudentsAdminController from '@/actions/App/Modules/Grades/Http/StudentsAdminController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import CopyLink from '../../components/copy-link';
import { sectionLabel } from '../../place';

type Stage = {
    stage: number;
    divided: boolean;
    students: Record<string, number>;
    subjects: {
        uuid: string;
        name: string;
        teacher: string | null;
        sections: Record<string, number>;
    }[];
};

/**
 * The owner's overview of the first month (ADR-0034): mark entry, the
 * results' publication to the students, the links for teachers and
 * students, and each stage's subjects with how many marks each group has.
 */
export default function GradesOverview({
    window,
    expected_subjects,
    teachers,
    stages,
    teacherLink,
    studentLink,
    canManage,
}: {
    window: {
        open: boolean;
        published: boolean;
        opened_at: string | null;
        closed_at: string | null;
    };
    expected_subjects: number;
    teachers: number;
    stages: Stage[];
    teacherLink: string;
    studentLink: string;
    canManage: boolean;
}) {
    const { t } = useTranslations();
    const put = (url: string, data: Record<string, string | boolean>) =>
        router.put(url, data, { preserveScroll: true });

    return (
        <>
            <Head title={t('First-month marks')} />
            <div className="flex flex-col gap-6 p-4">
                <Heading
                    title={t('First-month marks')}
                    description={t(
                        'Set up the department, follow the teachers, and read the results.',
                    )}
                />

                <nav
                    aria-label={t('First-month marks')}
                    className="flex flex-wrap gap-2"
                >
                    {canManage && (
                        <>
                            <Button variant="outline" asChild>
                                <Link
                                    href={GradesAdminController.teachers.url()}
                                >
                                    {t('Teachers')}
                                </Link>
                            </Button>
                            <Button variant="outline" asChild>
                                <Link
                                    href={GradesAdminController.subjects.url()}
                                >
                                    {t('Subjects')}
                                </Link>
                            </Button>
                        </>
                    )}
                    <Button variant="outline" asChild>
                        <Link href={StudentsAdminController.index.url()}>
                            {t('Students')}
                        </Link>
                    </Button>
                    <Button asChild>
                        <Link href={ResultsController.index.url()}>
                            {t('Results')}
                        </Link>
                    </Button>
                </nav>

                <section
                    className="grid gap-4 rounded-lg border bg-card p-4 md:grid-cols-2"
                    data-test="entry-window"
                >
                    <div className="flex flex-col gap-3">
                        <div className="flex items-center gap-3">
                            {window.open ? (
                                <LockOpen
                                    className="size-5 text-success"
                                    aria-hidden
                                />
                            ) : (
                                <Lock
                                    className="size-5 text-muted-foreground"
                                    aria-hidden
                                />
                            )}
                            <div>
                                <p className="font-medium">
                                    {window.open
                                        ? t('Mark entry is open.')
                                        : t('Mark entry is closed.')}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {t(':count teachers can sign in.', {
                                        count: String(teachers),
                                    })}
                                </p>
                            </div>
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
                                    className="size-5 text-success"
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
                                <p className="text-sm text-muted-foreground">
                                    {window.open
                                        ? t(
                                              'Close mark entry before publishing.',
                                          )
                                        : t(
                                              'Each student sees only their own result.',
                                          )}
                                </p>
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
                    </div>
                </section>

                {canManage && (
                    <section className="grid gap-6 rounded-lg border bg-card p-4 md:grid-cols-2">
                        <CopyLink
                            id="teacher-link"
                            href={teacherLink}
                            label={t('Link for the teachers')}
                            hint={t(
                                'Every teacher opens this link and types their own code.',
                            )}
                        />
                        <CopyLink
                            id="student-link"
                            href={studentLink}
                            label={t('Link for the students')}
                            hint={t(
                                'Every student opens this link and types the code printed for them.',
                            )}
                        />
                    </section>
                )}

                {stages.map((stage) => {
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
                                    {t('Stage :stage', {
                                        stage: String(stage.stage),
                                    })}
                                </h2>
                                <span className="text-sm text-muted-foreground">
                                    {sections
                                        .map((section) =>
                                            stage.divided
                                                ? t(':label: :count students', {
                                                      label: sectionLabel(
                                                          t,
                                                          section,
                                                          true,
                                                      ),
                                                      count: String(
                                                          stage.students[
                                                              section
                                                          ] ?? 0,
                                                      ),
                                                  })
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

                            {stage.subjects.length !== expected_subjects && (
                                <p className="m-4 mb-0 flex items-start gap-2 rounded-md border border-warning bg-warning/15 p-3 text-sm">
                                    <AlertTriangle
                                        className="mt-0.5 size-4 shrink-0"
                                        aria-hidden
                                    />
                                    {t(
                                        'This stage has :count subjects; :expected are expected.',
                                        {
                                            count: String(
                                                stage.subjects.length,
                                            ),
                                            expected: String(expected_subjects),
                                        },
                                    )}
                                </p>
                            )}

                            <div className="overflow-x-auto p-4">
                                <table className="w-full text-sm">
                                    <caption className="sr-only">
                                        {t('Stage :stage', {
                                            stage: String(stage.stage),
                                        })}
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
                                                    <bdi>{subject.name}</bdi>
                                                </th>
                                                <td className="p-2">
                                                    {subject.teacher ? (
                                                        <bdi>
                                                            {subject.teacher}
                                                        </bdi>
                                                    ) : (
                                                        <Badge variant="destructive">
                                                            {t('No teacher')}
                                                        </Badge>
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
                                                                String(done),
                                                            students:
                                                                String(total),
                                                        },
                                                    );

                                                    return (
                                                        <td
                                                            key={section}
                                                            className="p-2 tabular-nums"
                                                        >
                                                            {canManage ? (
                                                                <Link
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
                                                                            ? 'text-success underline-offset-4 hover:underline'
                                                                            : 'underline-offset-4 hover:underline'
                                                                    }
                                                                >
                                                                    {label}
                                                                </Link>
                                                            ) : (
                                                                label
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
