import { Form } from '@inertiajs/react';
import AppealsController from '@/actions/App/Modules/Grades/Http/AppealsController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useTranslations } from '@/hooks/use-translations';
import type { ResultCardData } from '../types';
import { useMoment } from './access-notice';

export type StudentAppeal = {
    uuid: string;
    subject: string | null;
    reason: string;
    status: 'pending' | 'accepted' | 'rejected';
    response: string | null;
    created_at: string;
};

const selectClass =
    'h-9 w-full rounded-md border border-input bg-background px-3 text-base shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';

/**
 * A student appeals a mark (ADR-0042): while the department allows it
 * after publication, one waiting appeal per subject; and the answers.
 */
export default function AppealPanel({
    card,
    until,
    appeals,
}: {
    card: ResultCardData;
    until: string | null;
    appeals: StudentAppeal[];
}) {
    const { t } = useTranslations();
    const moment = useMoment();
    const waiting = new Set(
        appeals
            .filter((appeal) => appeal.status === 'pending')
            .map((appeal) => appeal.subject),
    );
    const subjects = card.subjects.filter(
        (subject, i) =>
            (card.student.marks[i] ?? null) !== null &&
            !waiting.has(subject.name),
    );
    const status = {
        pending: t('Waiting for an answer'),
        accepted: t('Accepted'),
        rejected: t('Rejected'),
    };

    if (until === null && appeals.length === 0) {
        return null;
    }

    return (
        <section
            className="mt-8 grid gap-3 print:hidden"
            aria-labelledby="appeals-title"
            data-test="appeals"
        >
            <h2 id="appeals-title" className="font-semibold">
                {t('Appeal a mark')}
            </h2>

            {until !== null && subjects.length > 0 && (
                <Form
                    {...AppealsController.store.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="grid gap-3 rounded-md border bg-card p-3"
                    data-test="appeal-form"
                >
                    {({ errors, processing }) => (
                        <>
                            <p className="text-sm text-muted-foreground">
                                {t('You may appeal until :time.', {
                                    time: moment(until),
                                })}
                            </p>
                            <div className="grid gap-1">
                                <Label htmlFor="appeal-subject">
                                    {t('Subject')}
                                </Label>
                                <select
                                    id="appeal-subject"
                                    name="subject"
                                    required
                                    className={selectClass}
                                    data-test="appeal-subject"
                                >
                                    {subjects.map((subject) => (
                                        <option
                                            key={subject.uuid}
                                            value={subject.uuid}
                                        >
                                            {subject.name}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.subject} />
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="appeal-reason">
                                    {t('Why')}
                                </Label>
                                <Textarea
                                    id="appeal-reason"
                                    name="reason"
                                    required
                                    minLength={5}
                                    maxLength={500}
                                    dir="auto"
                                    className="min-h-20 bg-background"
                                    data-test="appeal-reason"
                                />
                                <InputError message={errors.reason} />
                            </div>
                            <Button
                                variant="outline"
                                className="self-start"
                                disabled={processing}
                                data-test="send-appeal"
                            >
                                {t('Send the appeal')}
                            </Button>
                        </>
                    )}
                </Form>
            )}

            {appeals.length > 0 && (
                <ul className="grid gap-2">
                    {appeals.map((appeal) => (
                        <li
                            key={appeal.uuid}
                            className="rounded-md border bg-card p-3 text-sm"
                            data-test="my-appeal"
                        >
                            <p className="font-medium">
                                <bdi>{appeal.subject}</bdi> ·{' '}
                                <span
                                    className={
                                        appeal.status === 'accepted'
                                            ? 'text-success-text'
                                            : appeal.status === 'rejected'
                                              ? 'text-destructive-text'
                                              : 'text-muted-foreground'
                                    }
                                >
                                    {status[appeal.status]}
                                </span>
                            </p>
                            <p
                                className="mt-1 font-normal whitespace-pre-line"
                                dir="auto"
                            >
                                {appeal.reason}
                            </p>
                            {appeal.response && (
                                <p
                                    className="mt-2 border-t pt-2 font-normal whitespace-pre-line"
                                    dir="auto"
                                >
                                    <span className="font-semibold">
                                        {t('Answer')}:{' '}
                                    </span>
                                    {appeal.response}
                                </p>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
