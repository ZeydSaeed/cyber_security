import { Head } from '@inertiajs/react';
import GradesAdminController from '@/actions/App/Modules/Grades/Http/GradesAdminController';
import ResultsController from '@/actions/App/Modules/Grades/Http/ResultsController';
import { useTranslations } from '@/hooks/use-translations';
import MarkSheet from '../../components/mark-sheet';
import { placeLabel } from '../../place';
import type { SheetStudent } from '../../types';

/**
 * The owner corrects a section's marks (ADR-0034): the teachers' sheet,
 * open at any time, each change recorded under the owner's name.
 */
export default function GradesSheet({
    open,
    subject,
    section,
    students,
}: {
    open: boolean;
    subject: { uuid: string; name: string; stage: number; divided: boolean };
    section: string;
    students: SheetStudent[];
}) {
    const { t } = useTranslations();

    return (
        <>
            <Head
                title={`${subject.name} · ${placeLabel(t, subject.stage, section, subject.divided)}`}
            />
            <div className="max-w-3xl p-4">
                <MarkSheet
                    subject={subject}
                    section={section}
                    students={students}
                    editable
                    saveUrl={ResultsController.saveSheet.url({
                        subject: subject.uuid,
                        section,
                    })}
                    back={{
                        href: GradesAdminController.overview.url(),
                        label: t('First-month marks'),
                    }}
                    notice={
                        <p
                            className="mb-6 rounded-md border bg-muted p-3 text-sm"
                            role="note"
                        >
                            {open
                                ? t(
                                      'You are correcting a teacher’s marks. Your changes are recorded under your name.',
                                  )
                                : t(
                                      'Mark entry is closed for teachers. You can still correct marks; your changes are recorded under your name.',
                                  )}
                        </p>
                    }
                />
            </div>
        </>
    );
}
