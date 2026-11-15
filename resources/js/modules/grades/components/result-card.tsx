import { useTranslations } from '@/hooks/use-translations';
import type { ResultRow } from '../types';

/**
 * One student's result (ADR-0034): each subject with its mark and whether
 * it is passed, then the total, the percentage and the outcome. On the
 * owner's printable card and on the student's own page alike.
 */
export default function ResultCard({
    pass_mark,
    subjects,
    student,
    showName = true,
}: {
    pass_mark: number;
    subjects: { uuid: string; name: string }[];
    student: ResultRow;
    /** The student's own page shows the name at the top already. */
    showName?: boolean;
}) {
    const { t } = useTranslations();

    const status = (mark: ResultRow['marks'][number]) => {
        if (mark === null) {
            return (
                <span className="text-muted-foreground">
                    {t('Not entered yet')}
                </span>
            );
        }
        if (mark === 'absent') {
            return (
                <span className="font-medium text-destructive">
                    {t('Absent')}
                </span>
            );
        }

        return mark >= pass_mark ? (
            t('Passed')
        ) : (
            <span className="font-medium text-destructive">
                {t('Below the pass mark')}
            </span>
        );
    };

    return (
        <>
            <dl className="mb-6 grid gap-x-6 gap-y-2 empty:hidden sm:grid-cols-2">
                {showName && (
                    <div>
                        <dt className="text-sm text-muted-foreground">
                            {t('Name')}
                        </dt>
                        <dd
                            className="text-lg font-semibold"
                            data-test="card-name"
                        >
                            <bdi>{student.name}</bdi>
                        </dd>
                    </div>
                )}
                {student.number && (
                    <div>
                        <dt className="text-sm text-muted-foreground">
                            {t('Number')}
                        </dt>
                        <dd className="text-lg">
                            <bdi>{student.number}</bdi>
                        </dd>
                    </div>
                )}
            </dl>

            <div
                className="overflow-x-auto"
                tabIndex={0}
                role="region"
                aria-label={t('Result card')}
            >
                <table
                    className="w-full border-collapse border-2 border-grid-line text-sm"
                    data-test="card-table"
                >
                    <caption className="sr-only">{t('Result card')}</caption>
                    <thead>
                        <tr>
                            <th
                                scope="col"
                                className="border border-grid-line p-2 text-start font-medium"
                            >
                                {t('Subject')}
                            </th>
                            <th
                                scope="col"
                                className="border border-grid-line p-2 text-center font-medium"
                            >
                                {t('Mark out of 100')}
                            </th>
                            <th
                                scope="col"
                                className="border border-grid-line p-2 text-center font-medium"
                            >
                                {t('Status')}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {subjects.map((subject, i) => {
                            const mark = student.marks[i] ?? null;

                            return (
                                <tr key={subject.uuid}>
                                    <th
                                        scope="row"
                                        className="border border-grid-line p-2 text-start font-normal"
                                    >
                                        <bdi>{subject.name}</bdi>
                                    </th>
                                    <td className="border border-grid-line p-2 text-center tabular-nums">
                                        {mark === null
                                            ? '—'
                                            : mark === 'absent'
                                              ? t('Absent')
                                              : mark}
                                    </td>
                                    <td className="border border-grid-line p-2 text-center">
                                        {status(mark)}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                    <tfoot>
                        <tr className="font-semibold">
                            <th
                                scope="row"
                                className="border border-grid-line p-2 text-start"
                            >
                                {t('Total')}
                            </th>
                            <td
                                className="border border-grid-line p-2 text-center tabular-nums"
                                data-test="card-total"
                            >
                                {student.total} / {student.maximum}
                            </td>
                            <td className="border border-grid-line p-2 text-center">
                                {student.complete
                                    ? `${student.percentage.toFixed(2)}%`
                                    : t('Incomplete (:missing missing)', {
                                          missing: String(student.missing),
                                      })}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p className="mt-6 text-base font-medium" data-test="card-outcome">
                {!student.complete
                    ? t('Some marks are not entered yet.')
                    : student.passed
                      ? t('Passed every subject.')
                      : t('Below the pass mark in :count subjects.', {
                            count: String(student.below),
                        })}
            </p>
            <p className="mt-2 text-sm text-muted-foreground">
                {t(
                    'Each subject is out of 100; :mark is the pass mark. An absence counts as 0. No ranking.',
                    {
                        mark: String(pass_mark),
                    },
                )}
            </p>
        </>
    );
}
