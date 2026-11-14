import { Head } from '@inertiajs/react';
import { useTranslations } from '@/hooks/use-translations';
import PrintPage from '../../components/print-page';
import type { ResultRow } from '../../types';

/**
 * One student's first-month result card, to print (ADR-0034): each subject
 * with its mark and whether it is passed, then the total and percentage.
 */
export default function PrintStudent({
    stage,
    section,
    pass_mark,
    subjects,
    student,
}: {
    stage: number;
    section: string;
    pass_mark: number;
    subjects: { uuid: string; name: string }[];
    student: ResultRow;
}) {
    const { t } = useTranslations();
    const place = `${t('Stage :stage', { stage: String(stage) })} · ${t('Section :section', { section })}`;

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
            <Head title={`${t('Result card')} · ${student.name}`} />
            <PrintPage title={t('First-month result card')} subtitle={place}>
                <dl className="mb-6 grid gap-x-6 gap-y-2 sm:grid-cols-2">
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
                    {student.number && (
                        <div>
                            <dt className="text-sm text-muted-foreground">
                                {t('Number')}
                            </dt>
                            <dd className="text-lg" dir="ltr">
                                {student.number}
                            </dd>
                        </div>
                    )}
                </dl>

                <table
                    className="w-full border-collapse text-sm"
                    data-test="card-table"
                >
                    <caption className="sr-only">{t('Result card')}</caption>
                    <thead>
                        <tr className="border-b">
                            <th
                                scope="col"
                                className="p-2 text-start font-medium"
                            >
                                {t('Subject')}
                            </th>
                            <th
                                scope="col"
                                className="p-2 text-center font-medium"
                            >
                                {t('Mark out of 100')}
                            </th>
                            <th
                                scope="col"
                                className="p-2 text-start font-medium"
                            >
                                {t('Status')}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {subjects.map((subject, i) => {
                            const mark = student.marks[i] ?? null;

                            return (
                                <tr key={subject.uuid} className="border-b">
                                    <th
                                        scope="row"
                                        className="p-2 text-start font-normal"
                                    >
                                        <bdi>{subject.name}</bdi>
                                    </th>
                                    <td className="p-2 text-center tabular-nums">
                                        {mark === null
                                            ? '—'
                                            : mark === 'absent'
                                              ? t('Absent')
                                              : mark}
                                    </td>
                                    <td className="p-2">{status(mark)}</td>
                                </tr>
                            );
                        })}
                    </tbody>
                    <tfoot>
                        <tr className="font-semibold">
                            <th scope="row" className="p-2 text-start">
                                {t('Total')}
                            </th>
                            <td
                                className="p-2 text-center tabular-nums"
                                data-test="card-total"
                            >
                                {student.total} / {student.maximum}
                            </td>
                            <td className="p-2">
                                {student.complete
                                    ? `${student.percentage.toFixed(2)}%`
                                    : t('Incomplete (:missing missing)', {
                                          missing: String(student.missing),
                                      })}
                            </td>
                        </tr>
                    </tfoot>
                </table>

                <p
                    className="mt-6 text-base font-medium"
                    data-test="card-outcome"
                >
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
                        { mark: String(pass_mark) },
                    )}
                </p>
            </PrintPage>
        </>
    );
}
