import { Head } from '@inertiajs/react';
import { useTranslations } from '@/hooks/use-translations';
import PrintPage from '../../components/print-page';
import ResultCard from '../../components/result-card';
import { placeLabel } from '../../place';
import type { ResultRow } from '../../types';

/** One student's first-month result card, to print (ADR-0034). */
export default function PrintStudent({
    stage,
    section,
    divided,
    pass_mark,
    subjects,
    student,
}: {
    stage: number;
    section: string;
    divided: boolean;
    pass_mark: number;
    subjects: { uuid: string; name: string }[];
    student: ResultRow;
}) {
    const { t } = useTranslations();

    return (
        <>
            <Head title={`${t('Result card')} · ${student.name}`} />
            <PrintPage
                title={t('First-month result card')}
                subtitle={placeLabel(t, stage, section, divided)}
            >
                <ResultCard
                    pass_mark={pass_mark}
                    subjects={subjects}
                    student={student}
                />
            </PrintPage>
        </>
    );
}
