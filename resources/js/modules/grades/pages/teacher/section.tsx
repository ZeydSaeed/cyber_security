import { Head } from '@inertiajs/react';
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
 * the department to change locked marks.
 */
export default function TeacherSection({
    subject,
    section,
    students,
    access,
    request,
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
}) {
    const { t } = useTranslations();

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
                    <AccessNotice
                        access={access}
                        hasLocked={students.some((student) => student.locked)}
                        request={request}
                        subject={subject.uuid}
                        section={section}
                    />
                }
            />
        </>
    );
}
