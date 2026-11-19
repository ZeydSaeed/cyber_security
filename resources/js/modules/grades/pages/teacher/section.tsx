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
}) {
    const { t } = useTranslations();
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
        </>
    );
}
