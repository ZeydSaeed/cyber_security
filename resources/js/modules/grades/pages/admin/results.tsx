import { Head, Link } from '@inertiajs/react';
import { FileSpreadsheet, Printer, Search } from 'lucide-react';
import { useEffect, useId, useMemo, useState } from 'react';
import ResultsController from '@/actions/App/Modules/Grades/Http/ResultsController';
import EmptyState from '@/components/empty-state';
import PageToolbar, { toolbarControls } from '@/components/page-toolbar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslations } from '@/hooks/use-translations';
import NoteDialog from '../../components/note-dialog';
import ResultsTable from '../../components/results-table';
import { matchRanges, searchTerms } from '../../name-search';
import { placeLabel } from '../../place';
import type { ResultRow, ResultSheet } from '../../types';

type Place = { stage: number; section: string; divided: boolean };

function query(stage: number, section: string): string {
    return `?stage=${stage}&section=${section}`;
}

/**
 * Results of one group of students (ADR-0034): the first stage, or a
 * section of the second, the others a click away; the printable sheet,
 * each student's result card and the Excel file. The title is in the top
 * toolbar; the groups' buttons, printing and the Excel file come first on
 * the page (owner's layout, 2026-11-16). A search in the toolbar marks
 * the matching names in yellow as each letter is typed, in the browser
 * (owner's request, 2026-11-18): Enter moves to the next match, Shift+Enter
 * to the one before, Escape clears.
 */
export default function GradesResults({
    places,
    ...sheet
}: ResultSheet & { places: Place[] }) {
    const { t } = useTranslations();
    const here = query(sheet.stage, sheet.section);
    const searchId = useId();
    const [search, setSearch] = useState('');
    const [index, setIndex] = useState(0);
    const terms = useMemo(() => searchTerms(search), [search]);
    // Behaviour and notes of one student (ADR-0039).
    const [noting, setNoting] = useState<ResultRow | null>(null);
    const matches = useMemo(
        () =>
            sheet.students
                .filter((student) => matchRanges(student.name, terms) !== null)
                .map((student) => student.uuid),
        [sheet.students, terms],
    );
    const current =
        matches.length === 0 ? null : matches[index % matches.length];

    // Brings the current match into view (the table scrolls sideways and
    // the page down).
    useEffect(() => {
        if (current !== null) {
            document
                .querySelector(`[data-student="${current}"]`)
                ?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        }
    }, [current]);

    return (
        <>
            <Head title={t('Results')} />
            <PageToolbar title={t('Results')}>
                <div className="flex items-center gap-2" role="search">
                    <label htmlFor={searchId} className="sr-only">
                        {t('Search by student name')}
                    </label>
                    {/* The field takes the page's colours back; the count
                        beside it keeps the toolbar's. */}
                    <div className={`relative ${toolbarControls}`}>
                        <Search
                            className="pointer-events-none absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden
                        />
                        <Input
                            id={searchId}
                            type="search"
                            value={search}
                            onChange={(event) => {
                                setSearch(event.target.value);
                                setIndex(0);
                            }}
                            onKeyDown={(event) => {
                                if (
                                    event.key === 'Enter' &&
                                    matches.length > 0
                                ) {
                                    event.preventDefault();
                                    setIndex(
                                        (at) =>
                                            (at +
                                                (event.shiftKey
                                                    ? matches.length - 1
                                                    : 1)) %
                                            matches.length,
                                    );
                                } else if (event.key === 'Escape') {
                                    setSearch('');
                                    setIndex(0);
                                }
                            }}
                            placeholder={t('Search by student name')}
                            className="h-8 w-56 bg-card ps-8 sm:w-72"
                            autoComplete="off"
                            dir="auto"
                            data-test="results-search"
                            // "/" moves focus here (keyboard shortcuts, #17).
                            data-shortcut-search
                        />
                    </div>
                    {/* Read out as it changes; empty until something is typed. */}
                    <span
                        role="status"
                        className="text-sm whitespace-nowrap tabular-nums"
                        data-test="results-search-count"
                    >
                        {terms.length === 0
                            ? ''
                            : matches.length === 0
                              ? t('No student matches')
                              : t(':current of :count', {
                                    current: String(
                                        (index % matches.length) + 1,
                                    ),
                                    count: String(matches.length),
                                })}
                    </span>
                </div>
            </PageToolbar>
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
                        terms={terms}
                        current={current}
                        action={(student) => (
                            <div className="flex flex-col items-start gap-1">
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
                                <button
                                    type="button"
                                    onClick={() => setNoting(student)}
                                    className="text-sm whitespace-nowrap underline-offset-4 hover:underline"
                                    data-test="open-note"
                                >
                                    {t('Behaviour and notes')}
                                </button>
                            </div>
                        )}
                    />
                )}
                {noting && (
                    <NoteDialog
                        student={noting}
                        onClose={() => setNoting(null)}
                    />
                )}
            </div>
        </>
    );
}
