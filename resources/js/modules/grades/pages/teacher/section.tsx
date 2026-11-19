import { Head } from '@inertiajs/react';
import { BadgeCheck } from 'lucide-react';
import ConfirmAction from '@/components/confirm-action';
import { Button } from '@/components/ui/button';
import TeacherMarksController from '@/actions/App/Modules/Grades/Http/TeacherMarksController';
import { useTranslations } from '@/hooks/use-translations';
import MarkSheet from '../../components/mark-sheet';
import AccessNotice from '../../components/access-notice';
import { placeLabel } from '../../place';
import type { EditRequest, SectionAccess, SheetStudent } from '../../types';

/**
 * A teacher's sheet for one section (ADR-0034): editable as the section's
 * access allows (ADR-0037: mark entry open, the mode, each mark's editing
 * time, or the department's grant), read-only otherwise, with a way to ask
 * the department to change locked marks. Once every student has a mark,
 * the teacher may approve them; approved marks lock (ADR-0041).
 */
export default function TeacherSection({
    subject,
    section,
    students,
    access,
    request,
    approved_at,
    appeals = [],
}: {
    subject: {
        uuid: string;
        name: string;
        stage: number;
        divided: boolean;
        max: number;
    };
    section: string;
    students: SheetStudent[];
    access: SectionAccess;
    request: EditRequest | null;
    /** When the teacher approved these marks; null: not approved. */
    approved_at: string | null;
    /** Students' appeals of this subject in this section (ADR-0042). */
    appeals?: {
        student: string;
        reason: string;
        status: 'pending' | 'accepted' | 'rejected';
        response: string | null;
        created_at: string;
    }[];
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
            <Head
                title={`${subject.name} · ${placeLabel(t, subject.stage, section, subject.divided)}`}
            />
            <MarkSheet
                subject={subject}
                section={section}
                students={students}
                editable={access.can_write}
                saveUrl={TeacherMarksController.save.url({
                    subject: subject.uuid,
                    section,
                })}
                back={{
                    href: TeacherMarksController.subjects.url(),
                    label: t('Your subjects'),
                }}
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
                    className="mt-8 grid gap-2"
                    aria-labelledby="section-appeals"
                    data-test="section-appeals"
                >
                    <h2 id="section-appeals" className="font-semibold">
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
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </>
    );
}
