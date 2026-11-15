import { Head, Link } from '@inertiajs/react';
import { FileSpreadsheet, Printer } from 'lucide-react';
import GradesAdminController from '@/actions/App/Modules/Grades/Http/GradesAdminController';
import ResultsController from '@/actions/App/Modules/Grades/Http/ResultsController';
import EmptyState from '@/components/empty-state';
import Heading from '@/components/heading';
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
 * each student's result card and the Excel file.
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
            <div className="flex flex-col gap-6 p-4">
                <Heading
                    title={t('Results')}
                    description={t(
                        'Each subject is out of 100; :mark is the pass mark. An absence counts as 0. No ranking.',
                        { mark: String(sheet.pass_mark) },
                    )}
                />

                <p className="text-sm">
                    <Link
                        href={GradesAdminController.overview.url()}
                        className="text-muted-foreground underline-offset-4 hover:underline"
                    >
                        {t('First-month marks')}
                    </Link>
                </p>

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
                                    aria-current={current ? 'page' : undefined}
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

                <div className="flex flex-wrap items-center gap-3">
                    <p
                        className="flex-1 text-sm text-muted-foreground"
                        data-test="results-summary"
                    >
                        {t(
                            ':students students · :complete with every mark · :passed passed every subject',
                            {
                                students: String(sheet.summary.students),
                                complete: String(sheet.summary.complete),
                                passed: String(sheet.summary.passed_all),
                            },
                        )}
                    </p>
                    <Button variant="outline" size="sm" asChild>
                        <a
                            href={ResultsController.printSection.url() + here}
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
                            <FileSpreadsheet className="size-4" aria-hidden />
                            {t('Excel file')}
                        </a>
                    </Button>
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
