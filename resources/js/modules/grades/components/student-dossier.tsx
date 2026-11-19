import { router } from '@inertiajs/react';
import {
    Eye,
    History,
    MessageSquareOff,
    MessageSquareText,
} from 'lucide-react';
import AccessController from '@/actions/App/Modules/Grades/Http/AccessController';
import EmptyState from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import type { MarkValue } from '../types';
import { useMoment } from './access-notice';

export type JourneyRow = {
    at: string;
    by: string;
    /** Changed by a user (the department), not the teacher. */
    owner: boolean;
    subject: string;
    from: MarkValue;
    to: MarkValue;
};

export type DossierAppeal = {
    uuid: string;
    subject: string | null;
    reason: string;
    status: 'pending' | 'accepted' | 'rejected';
    response: string | null;
    /** Who answered: a teacher's or a user's name. */
    by: string | null;
    by_teacher: boolean;
    created_at: string;
};

export type AppealAccess = {
    scope: 'all' | 'selected';
    allowed: boolean;
    uuid: string;
} | null;

/**
 * Below a student's card on the owner's page, never printed (ADR-0044):
 * each subject's mark from its first entry to its last change, with when
 * and who; the student's appeals and who answered them; and, when appeals
 * are for chosen students, whether this one may appeal.
 */
export type ResultView = {
    at: string;
    who: string;
    kind: 'student' | 'user' | 'teacher';
};

export default function StudentDossier({
    journeys,
    appeals,
    appealAccess,
    views = [],
}: {
    journeys: JourneyRow[];
    appeals: DossierAppeal[];
    appealAccess: AppealAccess;
    /** Who saw the result, newest first (ADR-0045). */
    views?: ResultView[];
}) {
    const { t } = useTranslations();
    const moment = useMoment();
    const show = (mark: MarkValue) =>
        mark === null ? '—' : mark === 'absent' ? t('Absent') : String(mark);
    const step = (row: JourneyRow) =>
        row.from === null
            ? t('First entry')
            : row.to === null
              ? t('Removed')
              : t('Changed');
    const bySubject = new Map<string, JourneyRow[]>();
    journeys.forEach((row) =>
        bySubject.set(row.subject, [
            ...(bySubject.get(row.subject) ?? []),
            row,
        ]),
    );
    const status = (appeal: DossierAppeal) =>
        appeal.status === 'pending'
            ? t('Waiting for an answer')
            : appeal.status === 'accepted'
              ? t('Accepted')
              : t('Rejected');

    return (
        <div
            className="mt-10 grid gap-8 print:hidden"
            data-test="student-dossier"
        >
            {appealAccess && appealAccess.scope === 'selected' && (
                <section className="flex flex-wrap items-center gap-3 rounded-md border p-3">
                    <p className="flex-1 text-sm">
                        {appealAccess.allowed
                            ? t('This student may appeal.')
                            : t(
                                  'Appeals are for chosen students; this student is not one of them.',
                              )}
                    </p>
                    <Button
                        variant="outline"
                        size="sm"
                        aria-pressed={appealAccess.allowed}
                        onClick={() =>
                            router.put(
                                AccessController.studentAppeal.url(
                                    appealAccess.uuid,
                                ),
                                { allowed: !appealAccess.allowed },
                                { preserveScroll: true },
                            )
                        }
                        data-test="dossier-toggle-appeal"
                    >
                        {appealAccess.allowed ? (
                            <MessageSquareOff aria-hidden />
                        ) : (
                            <MessageSquareText aria-hidden />
                        )}
                        {appealAccess.allowed
                            ? t('Withdraw the appeal')
                            : t('Allow an appeal')}
                    </Button>
                </section>
            )}

            <section
                className="grid gap-3"
                aria-labelledby="student-appeals"
                data-test="dossier-appeals"
            >
                <h2 id="student-appeals" className="font-semibold">
                    {t('Appeals')}
                </h2>
                {appeals.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('No appeals.')}
                    </p>
                ) : (
                    <ul className="grid gap-2">
                        {appeals.map((appeal) => (
                            <li
                                key={appeal.uuid}
                                className="rounded-md border p-3 text-sm"
                            >
                                <p className="font-medium">
                                    <bdi>{appeal.subject}</bdi> ·{' '}
                                    <span className="text-muted-foreground">
                                        {status(appeal)}
                                    </span>
                                </p>
                                <p
                                    className="mt-1 whitespace-pre-line"
                                    dir="auto"
                                >
                                    {appeal.reason}
                                </p>
                                {appeal.response && (
                                    <p
                                        className="mt-1 whitespace-pre-line text-muted-foreground"
                                        dir="auto"
                                    >
                                        {t('Answer: :text', {
                                            text: appeal.response,
                                        })}
                                    </p>
                                )}
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {moment(appeal.created_at)}
                                    {appeal.by &&
                                        ` · ${t('Answered by :name', { name: appeal.by })}`}
                                    {appeal.by_teacher &&
                                        ` (${t('the teacher')})`}
                                </p>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section
                className="grid gap-3"
                aria-labelledby="result-views"
                data-test="dossier-views"
            >
                <h2
                    id="result-views"
                    className="flex items-center gap-2 font-semibold"
                >
                    <Eye className="size-4" aria-hidden />
                    {t('Who saw the result')}
                </h2>
                {views.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('Nobody has seen it yet.')}
                    </p>
                ) : (
                    <ul className="grid gap-1 text-sm">
                        {views.map((view, i) => (
                            <li
                                key={`${view.at}-${i}`}
                                className="flex flex-wrap gap-x-2"
                                data-test="result-view"
                            >
                                <time
                                    dateTime={view.at}
                                    className="text-muted-foreground"
                                >
                                    {moment(view.at)}
                                </time>
                                <bdi>{view.who}</bdi>
                                <span className="text-muted-foreground">
                                    {view.kind === 'student'
                                        ? t('(the student)')
                                        : view.kind === 'teacher'
                                          ? t(
                                                "(a teacher, on the section's results)",
                                            )
                                          : t('(the department)')}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section
                className="grid gap-3"
                aria-labelledby="student-journeys"
                data-test="dossier-journeys"
            >
                <h2
                    id="student-journeys"
                    className="flex items-center gap-2 font-semibold"
                >
                    <History className="size-4" aria-hidden />
                    {t("The marks' journey")}
                </h2>
                {journeys.length === 0 ? (
                    <EmptyState
                        title={t('No changes yet')}
                        headingLevel={3}
                        bordered={false}
                    />
                ) : (
                    [...bySubject.entries()].map(([subject, rows]) => (
                        <div
                            key={subject}
                            className="overflow-x-auto rounded-lg border"
                            tabIndex={0}
                            role="region"
                            aria-label={subject}
                        >
                            <table className="w-full text-sm">
                                <caption className="border-b p-2 text-start font-medium">
                                    <bdi>{subject}</bdi>
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
                                            {t('Step')}
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
                                            data-test="journey-row"
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
                                            <td className="p-2">{step(row)}</td>
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
                    ))
                )}
            </section>
        </div>
    );
}
