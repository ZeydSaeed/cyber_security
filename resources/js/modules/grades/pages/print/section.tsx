import { Head } from '@inertiajs/react';
import { useTranslations } from '@/hooks/use-translations';
import PrintPage from '../../components/print-page';
import ResultsTable from '../../components/results-table';
import type { ResultSheet } from '../../types';

/** A section's results, to print (ADR-0034). */
export default function PrintSection(sheet: ResultSheet) {
    const { t } = useTranslations();
    const place = `${t('Stage :stage', { stage: String(sheet.stage) })} · ${t('Section :section', { section: sheet.section })}`;

    return (
        <>
            <Head title={`${t('First-month results')} · ${place}`} />
            <PrintPage title={t('First-month results')} subtitle={place}>
                <ResultsTable sheet={sheet} />
                <p className="mt-4 text-sm text-muted-foreground">
                    {t(
                        'Each subject is out of 100; :mark is the pass mark. An absence counts as 0. No ranking.',
                        { mark: String(sheet.pass_mark) },
                    )}
                </p>
            </PrintPage>
        </>
    );
}
