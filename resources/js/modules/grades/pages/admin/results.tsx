import { Head, Link } from '@inertiajs/react';
import { FileSpreadsheet, Printer } from 'lucide-react';
import ResultsController from '@/actions/App/Modules/Grades/Http/ResultsController';
import EmptyState from '@/components/empty-state';
import PageToolbar from '@/components/page-toolbar';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import ResultsTable from '../../components/results-table';
import { placeLabel } from '../../place';
import type { ResultSheet } from '../../types';

type Place = { stage: number; section: string; divided: boolean };

function query(stage: number, section: string): string {
    return `?stage=${stage}&section=${section}`;
}

/**
 * Results of one group of students (ADR-0034): the first stage, or a
 * section of the second, the others a click away; the printable sheet,
 * each student's result card and the Excel file. The title is in the top
 * toolbar; the groups' buttons, printing and the Excel file come first on
 * the page (owner's layout, 2026-11-16).
 */
export default function GradesResults({
    places,
    ...sheet
}: ResultSheet & { places: Place[] }) {
    const { t } = useTranslations();
    const here = query(sheet.stage, sheet.section);

    return (
        <>
            <Head title={t('Results')} />
            <PageToolbar title={t('Results')} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center gap-2">
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
                                    variant={current ? 'default' : 'outline'}
                                    size="sm"
                                    asChild
                                >
                                    <Link
                                        href={
                                            ResultsController.index.url() +
                                            query(place.stage, place.section)
                                        }
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
                    <div className="ms-auto flex flex-wrap gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <a
                                href={
                                    ResultsController.printSection.url() + here
                                }
                                target="_blank"
                                rel="noopener"
                            >
                                <Printer className="size-4" aria-hidden />
                                {t('Print the section')}
                            </a>
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <a
                                href={ResultsController.export.url() + here}
                                data-test="export-results"
                            >
                                <FileSpreadsheet
                                    className="size-4"
                                    aria-hidden
                                />
                                {t('Excel file')}
                            </a>
                        </Button>
                    </div>
                </div>

                {sheet.students.length === 0 || sheet.subjects.length === 0 ? (
                    <EmptyState
                        title={t('Nothing to show yet')}
                        description={t(
                            'This section needs students and its stage needs subjects.',
                        )}
                    />
                ) : (
                    <ResultsTable
                        sheet={sheet}
                        action={(student) => (
                            <a
                                href={ResultsController.printStudent.url(
                                    student.uuid,
                                )}
                                target="_blank"
                                rel="noopener"
                                className="text-sm whitespace-nowrap underline-offset-4 hover:underline"
                            >
                                {t('Result card')}
                            </a>
                        )}
                    />
                )}
            </div>
        </>
    );
}
