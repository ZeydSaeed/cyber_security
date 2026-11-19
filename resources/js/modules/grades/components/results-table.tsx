import type { ReactNode } from 'react';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';
import { matchRanges } from '../name-search';
import { placeLabel } from '../place';
import { bandLabel, markTone, toneClass } from '../result-words';
import type { MarkValue, ResultCell, ResultSheet } from '../types';

/**
 * The name with the searched parts marked in yellow (the palette's
 * highlight pair). The current match is outlined too.
 */
function MarkedName({
    name,
    terms,
    current,
}: {
    name: string;
    terms: string[];
    current: boolean;
}) {
    const ranges = matchRanges(name, terms);

    if (ranges === null) {
        return <bdi>{name}</bdi>;
    }

    const parts: ReactNode[] = [];
    let at = 0;
    for (const [start, end] of ranges) {
        if (start > at) {
            parts.push(name.slice(at, start));
        }
        parts.push(
            <mark
                key={start}
                className={cn(
                    'rounded-sm bg-highlight text-highlight-foreground',
                    current && 'ring-2 ring-ring',
                )}
            >
                {name.slice(start, end)}
            </mark>,
        );
        at = end;
    }
    if (at < name.length) {
        parts.push(name.slice(at));
    }

    return <bdi data-test="name-match">{parts}</bdi>;
}

/**
 * A section's results (ADR-0034): a row per student, a column per subject
 * of the stage, then the total, the percentage, subjects below the pass
 * mark and absences. A mark below the pass mark or an absence is marked
 * with words, not colour alone. Inner and outer grid lines (owner's
 * layout, 2026-11-16). Used on screen and in print. On screen, the parts
 * of the names that match a search are marked (`terms`, from
 * name-search.ts), and `current` names the match the page moved to.
 */
export default function ResultsTable({
    sheet,
    action,
    terms = [],
    current = null,
    columns = {},
}: {
    sheet: ResultSheet;
    action?: (student: ResultSheet['students'][number]) => React.ReactNode;
    terms?: string[];
    current?: string | null;
    /** Summary columns to leave out (a teacher's view, ADR-0040). */
    columns?: Partial<
        Record<'total' | 'percentage' | 'failed' | 'rank' | 'grade', boolean>
    >;
}) {
    const { t } = useTranslations();
    const show = {
        total: columns.total ?? true,
        percentage: columns.percentage ?? true,
        failed: columns.failed ?? true,
        rank: columns.rank ?? true,
        grade: columns.grade ?? true,
    };

    // A mark in its tone (ADR-0039), always with words for what the colour
    // says: below the pass mark, exempt.
    const cell = (mark: MarkValue, result: ResultCell) => {
        if (mark === null) {
            return <span className="text-muted-foreground">—</span>;
        }
        const tone = toneClass(markTone(mark, result, sheet.tones));
        if (mark === 'absent') {
            return <span className={tone}>{t('Absent')}</span>;
        }

        return (
            <span className={tone}>
                {mark}
                {result && !result.passed && (
                    <span className="sr-only">
                        {' '}
                        ({t('below the pass mark')})
                    </span>
                )}
                {result?.exempt && (
                    <span className="block text-xs font-normal">
                        {t('Exempt')}
                    </span>
                )}
            </span>
        );
    };

    return (
        // Scrolls sideways on a phone: reachable by keyboard, and named.
        <div
            className="overflow-x-auto rounded-lg border border-grid-line bg-card focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none print:overflow-visible"
            tabIndex={0}
            role="region"
            aria-label={t('Results')}
        >
            <table
                // The outer line is the region's own border.
                className="w-full border-collapse border-hidden text-sm [&_td]:border [&_td]:border-grid-line [&_th]:border [&_th]:border-grid-line"
                data-test="results-table"
            >
                <caption className="sr-only">
                    {placeLabel(t, sheet.stage, sheet.section, sheet.divided)}
                </caption>
                <thead>
                    <tr className="border-b">
                        <th scope="col" className="p-2 text-start font-medium">
                            #
                        </th>
                        <th
                            scope="col"
                            className="sticky start-0 bg-table-head p-2 text-start font-medium"
                        >
                            {t('Name')}
                        </th>
                        {sheet.subjects.map((subject) => (
                            <th
                                key={subject.uuid}
                                scope="col"
                                className="p-2 text-center font-medium"
                            >
                                <bdi>{subject.name}</bdi>
                                <span className="block text-xs font-normal text-muted-foreground tabular-nums">
                                    / {subject.max}
                                </span>
                            </th>
                        ))}
                        {show.total && (
                            <th
                                scope="col"
                                className="p-2 text-center font-medium"
                            >
                                {t('Total')}
                            </th>
                        )}
                        {show.percentage && (
                            <th
                                scope="col"
                                className="p-2 text-center font-medium"
                            >
                                %
                            </th>
                        )}
                        {show.failed && (
                            <th
                                scope="col"
                                className="p-2 text-center font-medium"
                            >
                                {t('Failed subjects')}
                            </th>
                        )}
                        <th scope="col" className="p-2 text-center font-medium">
                            {t('Absences')}
                        </th>
                        {show.rank && (
                            <th
                                scope="col"
                                className="p-2 text-center font-medium"
                            >
                                {t('Rank')}
                            </th>
                        )}
                        {show.grade && (
                            <th
                                scope="col"
                                className="p-2 text-center font-medium"
                            >
                                {t('Grade')}
                            </th>
                        )}
                        {action && (
                            <th scope="col" className="p-2 print:hidden">
                                <span className="sr-only">
                                    {t('Result card')}
                                </span>
                            </th>
                        )}
                    </tr>
                </thead>
                <tbody>
                    {sheet.students.map((student, index) => (
                        <tr
                            key={student.uuid}
                            className="border-b last:border-0"
                            data-test="result-row"
                            data-student={student.uuid}
                        >
                            <td className="p-2 text-muted-foreground tabular-nums">
                                {index + 1}
                            </td>
                            <th
                                scope="row"
                                className="sticky start-0 bg-card p-2 text-start font-medium"
                            >
                                <MarkedName
                                    name={student.name}
                                    terms={terms}
                                    current={current === student.uuid}
                                />
                            </th>
                            {student.marks.map((mark, i) => (
                                <td
                                    key={sheet.subjects[i]?.uuid ?? i}
                                    className="p-2 text-center tabular-nums"
                                >
                                    {cell(mark, student.cells[i] ?? null)}
                                </td>
                            ))}
                            {show.total && (
                                <td className="p-2 text-center font-semibold tabular-nums">
                                    {student.complete ? (
                                        `${student.total} / ${student.maximum}`
                                    ) : (
                                        <span className="text-muted-foreground">
                                            {t(
                                                'Incomplete (:missing missing)',
                                                {
                                                    missing: String(
                                                        student.missing,
                                                    ),
                                                },
                                            )}
                                        </span>
                                    )}
                                </td>
                            )}
                            {show.percentage && (
                                <td className="p-2 text-center tabular-nums">
                                    {student.complete &&
                                    student.percentage !== null
                                        ? student.percentage.toFixed(2)
                                        : '—'}
                                </td>
                            )}
                            {show.failed && (
                                <td className="p-2 text-center tabular-nums">
                                    {student.below ?? '—'}
                                </td>
                            )}
                            <td className="p-2 text-center tabular-nums">
                                {student.absences}
                            </td>
                            {show.rank && (
                                <td
                                    className="p-2 text-center tabular-nums"
                                    data-test="result-rank"
                                >
                                    {student.rank ?? '—'}
                                </td>
                            )}
                            {show.grade && (
                                <td className="p-2 text-center">
                                    {student.band
                                        ? bandLabel(t, student.band)
                                        : '—'}
                                    {student.exempt_all && (
                                        <span className="block text-xs">
                                            {t('Exempt')}
                                        </span>
                                    )}
                                </td>
                            )}
                            {action && (
                                <td className="p-2 print:hidden">
                                    {action(student)}
                                </td>
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
