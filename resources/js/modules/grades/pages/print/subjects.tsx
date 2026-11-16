import { Head } from '@inertiajs/react';
import { useTranslations } from '@/hooks/use-translations';
import PrintPage from '../../components/print-page';
import { stageLabel } from '../../place';

/**
 * The subjects of every stage in one table, to print (owner's request,
 * 2026-11-17): the head row holds the number in order and a column per
 * stage ("First stage", "Second stage", "Third stage"); each stage's
 * subjects are listed down its column. White paper and black lines in
 * every theme (PrintPage `paper`).
 */
export default function PrintSubjects({
    stages,
}: {
    stages: { stage: number; subjects: string[] }[];
}) {
    const { t } = useTranslations();
    const rows = Math.max(0, ...stages.map((stage) => stage.subjects.length));
    const cell = 'border border-print-ink p-2';

    return (
        <>
            <Head title={t('Subjects')} />
            <PrintPage title={t('Subjects')} paper>
                <table
                    className="w-full border-collapse border-2 border-print-ink text-sm"
                    data-test="subjects-table"
                >
                    <caption className="sr-only">{t('Subjects')}</caption>
                    {/* Black on white like the rest of the sheet, not the
                        screen's coloured table head. */}
                    <thead className="[&_tr]:bg-paper [&_tr]:bg-none [&_tr]:text-print-ink">
                        <tr>
                            <th
                                scope="col"
                                className={`${cell} w-12 text-center`}
                            >
                                #
                            </th>
                            {stages.map((stage) => (
                                <th
                                    key={stage.stage}
                                    scope="col"
                                    className={`${cell} text-center`}
                                >
                                    {stageLabel(t, stage.stage)}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {Array.from({ length: rows }, (_, index) => (
                            <tr key={index}>
                                <td
                                    className={`${cell} text-center tabular-nums`}
                                >
                                    {index + 1}
                                </td>
                                {stages.map((stage) => (
                                    <td key={stage.stage} className={cell}>
                                        {stage.subjects[index] && (
                                            <bdi>{stage.subjects[index]}</bdi>
                                        )}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </PrintPage>
        </>
    );
}
