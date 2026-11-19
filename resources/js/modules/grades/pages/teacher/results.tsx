import { Head, Link } from '@inertiajs/react';
import { ChevronLeft, EyeOff } from 'lucide-react';
import TeacherMarksController from '@/actions/App/Modules/Grades/Http/TeacherMarksController';
import TeacherResultsController from '@/actions/App/Modules/Grades/Http/TeacherResultsController';
import EmptyState from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import ResultsTable from '../../components/results-table';
import { placeLabel } from '../../place';
import { behaviourLabel } from '../../result-words';
import type { ResultSheet } from '../../types';

type Place = { stage: number; section: string; divided: boolean };

type TeacherView = Record<
    | 'other_subjects'
    | 'total'
    | 'percentage'
    | 'rank'
    | 'failed_count'
    | 'mark_grade'
    | 'overall_grade'
    | 'exemption'
    | 'student_status'
    | 'behaviour'
    | 'notes',
    boolean
>;

/**
 * A teacher's own students' results (ADR-0040): the sections they teach,
 * one at a time, with what the department lets them see. Nothing the
 * department hides reaches this page.
 */
export default function TeacherResults({
    allowed,
    places,
    sheet,
}: {
    allowed: boolean;
    places: Place[];
    sheet: (ResultSheet & { view: TeacherView }) | null;
}) {
    const { t } = useTranslations();
    const noted =
        sheet?.students.filter(
            (student) =>
                (sheet.view.behaviour && student.behaviour) ||
                (sheet.view.notes && student.notes),
        ) ?? [];

    return (
        <>
            <Head title={t("Your students' results")} />
            <Link
                href={TeacherMarksController.subjects.url()}
                className="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
            >
                <ChevronLeft className="size-4 rtl:-scale-x-100" aria-hidden />
                {t('Your subjects')}
            </Link>
            <h1 className="mb-4 text-xl font-semibold">
                {t("Your students' results")}
            </h1>

            {!allowed ? (
                <p
                    role="status"
                    className="flex items-start gap-2 rounded-md border bg-muted p-4 text-sm"
                    data-test="results-not-allowed"
                >
                    <EyeOff className="mt-0.5 size-4 shrink-0" aria-hidden />
                    {t(
                        'The department has not opened the results to teachers yet.',
                    )}
                </p>
            ) : sheet === null ? (
                <EmptyState
                    title={t('No sections yet')}
                    description={t(
                        'The department has not given you a subject yet.',
                    )}
                />
            ) : (
                <div className="flex flex-col gap-4">
                    {places.length > 1 && (
                        <nav
                            aria-label={t('Sections')}
                            className="flex flex-wrap gap-2"
                        >
                            {places.map((place) => {
                                const current =
                                    place.stage === sheet.stage &&
                                    place.section === sheet.section;

                                return (
                                    <Button
                                        key={`${place.stage}${place.section}`}
                                        variant={
                                            current ? 'default' : 'outline'
                                        }
                                        size="sm"
                                        asChild
                                    >
                                        <Link
                                            href={TeacherResultsController.show.url(
                                                {
                                                    query: {
                                                        stage: place.stage,
                                                        section: place.section,
                                                    },
                                                },
                                            )}
                                            aria-current={
                                                current ? 'page' : undefined
                                            }
                                        >
                                            {placeLabel(
                                                t,
                                                place.stage,
                                                place.section,
                                                place.divided,
                                            )}
                                        </Link>
                                    </Button>
                                );
                            })}
                        </nav>
                    )}
                    <p className="text-sm text-muted-foreground">
                        {placeLabel(
                            t,
                            sheet.stage,
                            sheet.section,
                            sheet.divided,
                        )}
                    </p>

                    <ResultsTable
                        sheet={sheet}
                        columns={{
                            total: sheet.view.total,
                            percentage: sheet.view.percentage,
                            failed: sheet.view.failed_count,
                            rank: sheet.view.rank,
                            grade:
                                sheet.view.overall_grade ||
                                sheet.view.exemption,
                        }}
                    />

                    {noted.length > 0 && (
                        <section
                            className="grid gap-2"
                            aria-labelledby="teacher-notes"
                            data-test="teacher-notes"
                        >
                            <h2 id="teacher-notes" className="font-semibold">
                                {t('Behaviour and notes')}
                            </h2>
                            <ul className="grid gap-2">
                                {noted.map((student) => (
                                    <li
                                        key={student.uuid}
                                        className="rounded-md border bg-card p-3 text-sm"
                                    >
                                        <p className="font-medium">
                                            <bdi>{student.name}</bdi>
                                            {student.behaviour && (
                                                <span className="text-muted-foreground">
                                                    {' · '}
                                                    {t('Behaviour')}:{' '}
                                                    {behaviourLabel(
                                                        t,
                                                        student.behaviour,
                                                    )}
                                                </span>
                                            )}
                                        </p>
                                        {student.notes && (
                                            <p
                                                className="mt-1 font-normal whitespace-pre-line"
                                                dir="auto"
                                            >
                                                {student.notes}
                                            </p>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                </div>
            )}
        </>
    );
}
