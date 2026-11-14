import { Head } from '@inertiajs/react';
import TeacherMarksController from '@/actions/App/Modules/Grades/Http/TeacherMarksController';
import { useTranslations } from '@/hooks/use-translations';
import MarkSheet from '../../components/mark-sheet';
import WindowNotice from '../../components/window-notice';
import type { SheetStudent } from '../../types';

/**
 * A teacher's sheet for one section (ADR-0034): editable while mark entry
 * is open, read-only after.
 */
export default function TeacherSection({
    open,
    subject,
    section,
    students,
}: {
    open: boolean;
    subject: { uuid: string; name: string; stage: number };
    section: string;
    students: SheetStudent[];
}) {
    const { t } = useTranslations();

    return (
        <>
            <Head
                title={`${subject.name} · ${t('Section :section', { section })}`}
            />
            <MarkSheet
                subject={subject}
                section={section}
                students={students}
                editable={open}
                saveUrl={TeacherMarksController.save.url({
                    subject: subject.uuid,
                    section,
                })}
                back={{
                    href: TeacherMarksController.subjects.url(),
                    label: t('Your subjects'),
                }}
                notice={<WindowNotice open={open} />}
            />
        </>
    );
}
