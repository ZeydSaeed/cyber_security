import { Head, router } from '@inertiajs/react';
import { History } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import TeacherMarksController from '@/actions/App/Modules/Grades/Http/TeacherMarksController';
import { useTranslations } from '@/hooks/use-translations';
import MarkHistory from '../../components/mark-history';
import type { HistoryRow } from '../../components/mark-history';
import TeacherSectionWork from '../../components/teacher-section-work';
import type { TeacherSectionData } from '../../components/teacher-section-work';
import { placeLabel } from '../../place';

/**
 * A teacher's sheet for one section (ADR-0034): editable as the section's
 * access allows (ADR-0037: mark entry open, the mode, each mark's editing
 * time, or the department's grant), read-only otherwise, with a way to ask
 * the department to change locked marks. Once every student has a mark,
 * the teacher may approve them; approved marks lock (ADR-0041).
 */
export default function TeacherSection({
    history,
    subject,
    ...data
}: TeacherSectionData & {
    /** Each mark's journey (ADR-0043): sent only once asked for. */
    history?: HistoryRow[];
    subject: {
        uuid: string;
        name: string;
        stage: number;
        divided: boolean;
        max: number;
    };
}) {
    const { t } = useTranslations();
    const [loading, setLoading] = useState(false);
    const loadHistory = () =>
        router.reload({
            only: ['history'],
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });

    return (
        <>
            <Head
                title={`${subject.name} · ${placeLabel(t, subject.stage, data.section, subject.divided)}`}
            />
            <TeacherSectionWork
                {...data}
                subject={subject}
                back={{
                    href: TeacherMarksController.subject.url(subject.uuid),
                    label: subject.name,
                }}
            />
            {history ? (
                <MarkHistory rows={history} />
            ) : (
                <div className="mt-8">
                    <Button
                        variant="outline"
                        onClick={loadHistory}
                        disabled={loading}
                        data-test="show-history"
                    >
                        <History aria-hidden />
                        {t("Show the marks' history")}
                    </Button>
                </div>
            )}
        </>
    );
}
