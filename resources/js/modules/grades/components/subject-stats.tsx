import { useTranslations } from '@/hooks/use-translations';
import type { ResultSheet } from '../types';

/**
 * Each subject's figures for the section (ADR-0041): marks entered,
 * absences, pass rate, highest, lowest and mean of the marks present.
 */
export default function SubjectStats({ sheet }: { sheet: ResultSheet }) {
    const { t } = useTranslations();
    const value = (number: number | null, suffix = '') =>
        number === null ? '—' : `${number}${suffix}`;

    return (
        <section
            className="grid gap-2"
            aria-labelledby="subject-stats"
            data-test="subject-stats"
        >
            <h2 id="subject-stats" className="font-semibold">
                {t('Subject figures')}
            </h2>
            <div
                className="overflow-x-auto rounded-lg border bg-card"
                tabIndex={0}
                role="region"
                aria-label={t('Subject figures')}
            >
                <table className="w-full text-sm">
                    <caption className="sr-only">
                        {t('Subject figures')}
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
                                className="p-2 text-center font-medium"
                            >
                                {t('Entered')}
                            </th>
                            <th
                                scope="col"
                                className="p-2 text-center font-medium"
                            >
                                {t('Absences')}
                            </th>
                            <th
                                scope="col"
                                className="p-2 text-center font-medium"
                            >
                                {t('Pass rate')}
                            </th>
                            <th
                                scope="col"
                                className="p-2 text-center font-medium"
                            >
                                {t('Highest')}
                            </th>
                            <th
                                scope="col"
                                className="p-2 text-center font-medium"
                            >
                                {t('Lowest')}
                            </th>
                            <th
                                scope="col"
                                className="p-2 text-center font-medium"
                            >
                                {t('Mean')}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {sheet.subjects.map((subject, i) => {
                            const stats = sheet.stats[i];

                            return (
                                <tr
                                    key={subject.uuid}
                                    className="border-t"
                                    data-test="stats-row"
                                >
                                    <th
                                        scope="row"
                                        className="p-2 text-start font-medium"
                                    >
                                        <bdi>{subject.name}</bdi>
                                        <span className="text-xs font-normal text-muted-foreground">
                                            {' '}
                                            / {subject.max}
                                        </span>
                                    </th>
                                    <td className="p-2 text-center tabular-nums">
                                        {stats
                                            ? `${stats.entered} / ${sheet.students.length}`
                                            : '—'}
                                    </td>
                                    <td className="p-2 text-center tabular-nums">
                                        {stats?.absent ?? '—'}
                                    </td>
                                    <td className="p-2 text-center tabular-nums">
                                        {value(stats?.pass_rate ?? null, '%')}
                                    </td>
                                    <td className="p-2 text-center tabular-nums">
                                        {value(stats?.highest ?? null)}
                                    </td>
                                    <td className="p-2 text-center tabular-nums">
                                        {value(stats?.lowest ?? null)}
                                    </td>
                                    <td className="p-2 text-center tabular-nums">
                                        {value(stats?.mean ?? null)}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </section>
    );
}
