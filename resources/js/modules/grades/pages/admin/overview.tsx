import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Lock, LockOpen } from 'lucide-react';
import GradesAdminController from '@/actions/App/Modules/Grades/Http/GradesAdminController';
import ResultsController from '@/actions/App/Modules/Grades/Http/ResultsController';
import StudentsAdminController from '@/actions/App/Modules/Grades/Http/StudentsAdminController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import CopyLink from '../../components/copy-link';

type Stage = {
    stage: number;
    students: Record<string, number>;
    subjects: {
        uuid: string;
        name: string;
        teacher: string | null;
        sections: Record<string, number>;
    }[];
};

/**
 * The owner's overview of the first month (ADR-0034): the entry window,
 * the link for the teachers, and each stage's subjects with how many marks
 * each section has, so what is missing is seen at a glance.
 */
export default function GradesOverview({
    window,
    expected_subjects,
    teachers,
    stages,
    teacherLink,
    canManage,
}: {
    window: {
        open: boolean;
        opened_at: string | null;
        closed_at: string | null;
    };
    expected_subjects: number;
    teachers: number;
    stages: Stage[];
    teacherLink: string;
    canManage: boolean;
}) {
    const { t } = useTranslations();

    const setWindow = (status: 'open' | 'closed') =>
        router.put(
            GradesAdminController.window.url(),
            { status },
            { preserveScroll: true },
        );

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
                    className="flex flex-col gap-4 rounded-lg border bg-card p-4 md:flex-row md:items-center"
                    data-test="entry-window"
                >
                    <div className="flex flex-1 items-center gap-3">
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
                                onClick={() => setWindow('closed')}
                                data-test="close-window"
                            >
                                {t('Close mark entry')}
                            </Button>
                        ) : (
                            <Button
                                onClick={() => setWindow('open')}
                                data-test="open-window"
                            >
                                {t('Open mark entry')}
                            </Button>
                        ))}
                </section>

                {canManage && (
                    <section className="rounded-lg border bg-card p-4">
                        <CopyLink href={teacherLink} />
                    </section>
                )}

                {stages.map((stage) => (
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
                                {t(
                                    'Section A: :a students · Section B: :b students',
                                    {
                                        a: String(stage.students.A ?? 0),
                                        b: String(stage.students.B ?? 0),
                                    },
                                )}
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
                                        count: String(stage.subjects.length),
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
                                    <tr className="text-start text-muted-foreground">
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
                                        {['A', 'B'].map((section) => (
                                            <th
                                                key={section}
                                                scope="col"
                                                className="p-2 text-start font-medium"
                                            >
                                                {t('Section :section', {
                                                    section,
                                                })}
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
                                                {subject.teacher ?? (
                                                    <Badge variant="destructive">
                                                        {t('No teacher')}
                                                    </Badge>
                                                )}
                                            </td>
                                            {['A', 'B'].map((section) => {
                                                const total =
                                                    stage.students[section] ??
                                                    0;
                                                const done =
                                                    subject.sections[section] ??
                                                    0;
                                                const label = t(
                                                    ':entered of :students entered',
                                                    {
                                                        entered: String(done),
                                                        students: String(total),
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
                                                                    total > 0 &&
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
                ))}
            </div>
        </>
    );
}
