import { History } from 'lucide-react';
import { useId, useMemo, useState } from 'react';
import EmptyState from '@/components/empty-state';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/hooks/use-translations';
import type { MarkValue } from '../types';
import { useMoment } from './access-notice';

export type HistoryRow = {
    at: string;
    by: string;
    /** Changed by a user (the department), not the teacher. */
    owner: boolean;
    student: string;
    /** Groups one mark's journey (ADR-0043). */
    student_uuid: string;
    from: MarkValue;
    to: MarkValue;
};

const selectClass =
    'h-9 w-full rounded-md border border-input bg-background px-3 text-base shadow-xs outline-none focus:border-ring focus:ring-[3px] focus:ring-ring/50 focus:outline-2 focus:outline-solid focus:outline-ring md:text-sm';

/**
 * Every change of a section's marks (ADR-0041): when, who, which student,
 * from what to what. Read from the audit records. Choosing a student shows
 * that mark's journey (ADR-0043), from its first entry to its last change,
 * oldest first; otherwise every change, newest first.
 */
export default function MarkHistory({ rows }: { rows: HistoryRow[] }) {
    const { t } = useTranslations();
    const moment = useMoment();
    const [student, setStudent] = useState('');
    // Unique: a subject's page holds one history per section.
    const id = useId();
    const show = (mark: MarkValue) =>
        mark === null ? '—' : mark === 'absent' ? t('Absent') : String(mark);

    const students = useMemo(() => {
        const names = new Map<string, string>();
        rows.forEach((row) => names.set(row.student_uuid, row.student));

        return [...names.entries()].sort((a, b) => a[1].localeCompare(b[1]));
    }, [rows]);
    const shown = student
        ? rows.filter((row) => row.student_uuid === student).reverse()
        : rows;
    const step = (row: HistoryRow) =>
        row.from === null
            ? t('First entry')
            : row.to === null
              ? t('Removed')
              : t('Changed');

    return (
        <section
            className="mt-8 grid gap-3"
            aria-labelledby={`${id}-title`}
            data-test="mark-history"
        >
            <h2
                id={`${id}-title`}
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
                <>
                    <div className="grid max-w-sm gap-1">
                        <Label htmlFor={`${id}-student`}>
                            {t("A mark's journey")}
                        </Label>
                        <select
                            id={`${id}-student`}
                            value={student}
                            onChange={(event) => setStudent(event.target.value)}
                            className={selectClass}
                            data-test="history-student"
                        >
                            <option value="">{t('Every change')}</option>
                            {students.map(([uuid, name]) => (
                                <option key={uuid} value={uuid}>
                                    {name}
                                </option>
                            ))}
                        </select>
                    </div>
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
                                        {student ? t('Step') : t('Student')}
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
                                {shown.map((row, i) => (
                                    <tr
                                        key={`${row.at}-${row.student_uuid}-${i}`}
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
                                            {student ? (
                                                step(row)
                                            ) : (
                                                <bdi>{row.student}</bdi>
                                            )}
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
                </>
            )}
        </section>
    );
}
