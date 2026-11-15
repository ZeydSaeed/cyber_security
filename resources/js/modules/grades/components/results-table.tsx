import { useTranslations } from '@/hooks/use-translations';
import { placeLabel } from '../place';
import type { MarkValue, ResultSheet } from '../types';

/**
 * A section's results (ADR-0034): a row per student, a column per subject
 * of the stage, then the total, the percentage, subjects below the pass
 * mark and absences. A mark below the pass mark or an absence is marked
 * with words, not colour alone. Used on screen and in print.
 */
export default function ResultsTable({
    sheet,
    action,
}: {
    sheet: ResultSheet;
    action?: (student: ResultSheet['students'][number]) => React.ReactNode;
}) {
    const { t } = useTranslations();

    const cell = (mark: MarkValue) => {
        if (mark === null) {
            return <span className="text-muted-foreground">—</span>;
        }
        if (mark === 'absent') {
            return (
                <span className="font-medium text-destructive">
                    {t('Absent')}
                </span>
            );
        }

        return mark < sheet.pass_mark ? (
            <span className="font-semibold text-destructive">
                {mark}
                <span className="sr-only"> ({t('below the pass mark')})</span>
            </span>
        ) : (
            mark
        );
    };

    return (
        // Scrolls sideways on a phone: reachable by keyboard, and named.
        <div
            className="overflow-x-auto rounded-lg border bg-card focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none print:overflow-visible print:border-0"
            tabIndex={0}
            role="region"
            aria-label={t('Results')}
        >
            <table
                className="w-full border-collapse text-sm"
                data-test="results-table"
            >
                <caption className="sr-only">
                    {placeLabel(t, sheet.stage, sheet.section, sheet.divided)}
                </caption>
                <thead>
                    <tr className="border-b text-muted-foreground print:text-foreground">
                        <th scope="col" className="p-2 text-start font-medium">
                            #
                        </th>
                        <th
                            scope="col"
                            className="sticky start-0 bg-card p-2 text-start font-medium"
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
                            </th>
                        ))}
                        <th scope="col" className="p-2 text-center font-medium">
                            {t('Total')}
                        </th>
                        <th scope="col" className="p-2 text-center font-medium">
                            %
                        </th>
                        <th scope="col" className="p-2 text-center font-medium">
                            {t('Below :mark', {
                                mark: String(sheet.pass_mark),
                            })}
                        </th>
                        <th scope="col" className="p-2 text-center font-medium">
                            {t('Absences')}
                        </th>
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
                        >
                            <td className="p-2 text-muted-foreground tabular-nums">
                                {index + 1}
                            </td>
                            <th
                                scope="row"
                                className="sticky start-0 bg-card p-2 text-start font-medium"
                            >
                                <bdi>{student.name}</bdi>
                            </th>
                            {student.marks.map((mark, i) => (
                                <td
                                    key={sheet.subjects[i]?.uuid ?? i}
                                    className="p-2 text-center tabular-nums"
                                >
                                    {cell(mark)}
                                </td>
                            ))}
                            <td className="p-2 text-center font-semibold tabular-nums">
                                {student.complete ? (
                                    `${student.total} / ${student.maximum}`
                                ) : (
                                    <span className="text-muted-foreground">
                                        {t('Incomplete (:missing missing)', {
                                            missing: String(student.missing),
                                        })}
                                    </span>
                                )}
                            </td>
                            <td className="p-2 text-center tabular-nums">
                                {student.complete
                                    ? student.percentage.toFixed(2)
                                    : '—'}
                            </td>
                            <td className="p-2 text-center tabular-nums">
                                {student.below}
                            </td>
                            <td className="p-2 text-center tabular-nums">
                                {student.absences}
                            </td>
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
