import { router } from '@inertiajs/react';
import { BadgeCheck, Check, X } from 'lucide-react';
import { useState } from 'react';
import ConfirmAction from '@/components/confirm-action';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import TeacherMarksController from '@/actions/App/Modules/Grades/Http/TeacherMarksController';
import { useTranslations } from '@/hooks/use-translations';
import type { EditRequest, SectionAccess, SheetStudent } from '../types';
import AccessNotice from './access-notice';
import MarkSheet from './mark-sheet';

export type SectionAppeal = {
    uuid: string;
    /** The owner lets teachers answer, and it waits (ADR-0044). */
    can_answer: boolean;
    student: string;
    reason: string;
    status: 'pending' | 'accepted' | 'rejected';
    response: string | null;
    created_at: string;
};

/** The teacher's answer to one appeal: accept or reject, with words. */
function AppealAnswer({ appeal }: { appeal: SectionAppeal }) {
    const { t } = useTranslations();
    const [response, setResponse] = useState('');
    const [busy, setBusy] = useState(false);
    const answer = (decision: 'accept' | 'reject') =>
        router.put(
            TeacherMarksController.answerAppeal.url(appeal.uuid),
            { decision, response },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            },
        );

    return (
        <div className="mt-2 grid gap-2" data-test="appeal-answer">
            <Label htmlFor={`answer-${appeal.uuid}`} className="text-xs">
                {t('Answer to the student')}
            </Label>
            <Input
                id={`answer-${appeal.uuid}`}
                value={response}
                onChange={(event) => setResponse(event.target.value)}
                maxLength={500}
                dir="auto"
                className="bg-background"
            />
            <div className="flex flex-wrap gap-2">
                <Button
                    size="sm"
                    disabled={busy}
                    onClick={() => answer('accept')}
                    data-test="teacher-accept-appeal"
                >
                    <Check aria-hidden />
                    {t('Accept')}
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    disabled={busy}
                    onClick={() => answer('reject')}
                    data-test="teacher-reject-appeal"
                >
                    <X aria-hidden />
                    {t('Reject')}
                </Button>
            </div>
            <p className="text-xs text-muted-foreground">
                {t(
                    'Accepting does not change the mark: correct it on the sheet, where the change is recorded.',
                )}
            </p>
        </div>
    );
}

/** One section of a teacher's subject, as the server sends it. */
export type TeacherSectionData = {
    section: string;
    students: SheetStudent[];
    access: SectionAccess;
    request: EditRequest | null;
    /** When the teacher approved these marks; null: not approved. */
    approved_at: string | null;
    /** Students' appeals of this subject in this section (ADR-0042). */
    appeals?: SectionAppeal[];
};

/**
 * A teacher's work on one section (ADR-0034): the sheet, editable as the
 * section's access allows (ADR-0037), the access notice with its request
 * form, the approval (ADR-0041) and the students' appeals (ADR-0042,
 * ADR-0044). On the section's own page and, one after another, on the
 * subject's page (owner's request, 2026-11-19).
 */
export default function TeacherSectionWork({
    subject,
    section,
    students,
    access,
    request,
    approved_at,
    appeals = [],
    back,
    within = false,
}: TeacherSectionData & {
    subject: {
        uuid: string;
        name: string;
        stage: number;
        divided: boolean;
        max: number;
    };
    back?: { href: string; label: string };
    within?: boolean;
}) {
    const { t } = useTranslations();
    // Marks that lock within a day (timed mode): a warning first (ADR-0042).
    const soon = students.filter(
        (student) =>
            student.locks_at &&
            new Date(student.locks_at).getTime() - Date.now() < 86_400_000,
    ).length;
    const complete =
        students.length > 0 &&
        students.every((student) => student.mark !== null);

    return (
        <>
            <MarkSheet
                subject={subject}
                section={section}
                students={students}
                editable={access.can_write}
                saveUrl={TeacherMarksController.save.url({
                    subject: subject.uuid,
                    section,
                })}
                back={back}
                within={within}
                notice={
                    <>
                        <AccessNotice
                            access={access}
                            hasLocked={students.some(
                                (student) => student.locked,
                            )}
                            request={request}
                            subject={subject.uuid}
                            section={section}
                        />
                        {soon > 0 && (
                            <p
                                role="status"
                                className="mb-4 rounded-md border border-warning bg-warning/15 p-3 text-sm"
                                data-test="locks-soon"
                            >
                                {t(
                                    ':count marks lock within a day. Check them now.',
                                    { count: String(soon) },
                                )}
                            </p>
                        )}
                        {approved_at === null &&
                            access.can_write &&
                            complete && (
                                <div className="mb-6">
                                    <ConfirmAction
                                        trigger={
                                            <Button
                                                variant="outline"
                                                data-test="approve-marks"
                                            >
                                                <BadgeCheck aria-hidden />
                                                {t('Approve the marks')}
                                            </Button>
                                        }
                                        title={t('Approve these marks?')}
                                        description={t(
                                            'Save any change first. Approved marks lock; only the department can open them again.',
                                        )}
                                        confirmLabel={t('Approve')}
                                        tone="default"
                                        form={TeacherMarksController.approve.form(
                                            { subject: subject.uuid, section },
                                        )}
                                    />
                                </div>
                            )}
                    </>
                }
            />
            {appeals.length > 0 && (
                <section
                    className="mt-6 grid gap-2"
                    aria-labelledby={`section-appeals-${subject.uuid}-${section}`}
                    data-test="section-appeals"
                >
                    <h2
                        id={`section-appeals-${subject.uuid}-${section}`}
                        className="font-semibold"
                    >
                        {t("Students' appeals")}
                    </h2>
                    <ul className="grid gap-2">
                        {appeals.map((appeal, i) => (
                            <li
                                key={`${appeal.created_at}-${i}`}
                                className="rounded-md border bg-card p-3 text-sm"
                            >
                                <p className="font-medium">
                                    <bdi>{appeal.student}</bdi> ·{' '}
                                    <span className="text-muted-foreground">
                                        {appeal.status === 'pending'
                                            ? t('Waiting for an answer')
                                            : appeal.status === 'accepted'
                                              ? t('Accepted')
                                              : t('Rejected')}
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
                                        className="mt-1 whitespace-pre-line text-muted-foreground"
                                        dir="auto"
                                    >
                                        {t('Answer: :text', {
                                            text: appeal.response,
                                        })}
                                    </p>
                                )}
                                {appeal.can_answer && (
                                    <AppealAnswer appeal={appeal} />
                                )}
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </>
    );
}
