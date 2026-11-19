import { History } from 'lucide-react';
import EmptyState from '@/components/empty-state';
import { useTranslations } from '@/hooks/use-translations';
import type { MarkValue } from '../types';
import { useMoment } from './access-notice';

export type HistoryRow = {
    at: string;
    by: string;
    /** Changed by a user (the department), not the teacher. */
    owner: boolean;
    student: string;
    from: MarkValue;
    to: MarkValue;
};

/**
 * Every change of a section's marks, newest first (ADR-0041): when, who,
 * which student, from what to what. Read from the audit records.
 */
export default function MarkHistory({ rows }: { rows: HistoryRow[] }) {
    const { t } = useTranslations();
    const moment = useMoment();
    const show = (mark: MarkValue) =>
        mark === null ? '—' : mark === 'absent' ? t('Absent') : String(mark);

    return (
        <section
            className="mt-8 grid gap-3"
            aria-labelledby="mark-history"
            data-test="mark-history"
        >
            <h2
                id="mark-history"
                className="flex items-center gap-2 font-semibold"
            >
                <History className="size-4" aria-hidden />
                {t('Change history')}
            </h2>
            {rows.length === 0 ? (
                <EmptyState
                    title={t('No changes yet')}
                    headingLevel={3}
                    bordered={false}
                />
            ) : (
                <div
                    className="overflow-x-auto rounded-lg border bg-card"
                    tabIndex={0}
                    role="region"
                    aria-label={t('Change history')}
                >
                    <table className="w-full text-sm">
                        <caption className="sr-only">
                            {t('Change history')}
                        </caption>
                        <thead>
                            <tr className="text-muted-foreground">
                                <th
                                    scope="col"
                                    className="p-2 text-start font-medium"
                                >
                                    {t('When')}
                                </th>
                                <th
                                    scope="col"
                                    className="p-2 text-start font-medium"
                                >
                                    {t('By')}
                                </th>
                                <th
                                    scope="col"
                                    className="p-2 text-start font-medium"
                                >
                                    {t('Student')}
                                </th>
                                <th
                                    scope="col"
                                    className="p-2 text-center font-medium"
                                >
                                    {t('From')}
                                </th>
                                <th
                                    scope="col"
                                    className="p-2 text-center font-medium"
                                >
                                    {t('To')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row, i) => (
                                <tr
                                    key={`${row.at}-${i}`}
                                    className="border-t"
                                    data-test="history-row"
                                >
                                    <td className="p-2 whitespace-nowrap">
                                        <time dateTime={row.at}>
                                            {moment(row.at)}
                                        </time>
                                    </td>
                                    <td className="p-2">
                                        <bdi>{row.by}</bdi>
                                        {row.owner && (
                                            <span className="block text-xs text-muted-foreground">
                                                {t('Department')}
                                            </span>
                                        )}
                                    </td>
                                    <td className="p-2">
                                        <bdi>{row.student}</bdi>
                                    </td>
                                    <td className="p-2 text-center tabular-nums">
                                        {show(row.from)}
                                    </td>
                                    <td className="p-2 text-center font-semibold tabular-nums">
                                        {show(row.to)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}
