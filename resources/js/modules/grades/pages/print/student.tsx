import { Head, usePage } from '@inertiajs/react';
import { Download, LoaderCircle } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import PrintPage from '../../components/print-page';
import ResultCard from '../../components/result-card';
import StudentDossier from '../../components/student-dossier';
import type {
    AppealAccess,
    DossierAppeal,
    JourneyRow,
    ResultView,
} from '../../components/student-dossier';
import { downloadResultCard } from '../../download-card';
import type { ResultCardData } from '../../types';

/**
 * One student's first-month result card, to print or to save as an image
 * on a phone or a computer (ADR-0034; owner's request, 2026-11-16).
 */
export default function PrintStudent(
    card: ResultCardData & {
        /** Below the card, not printed (ADR-0044). */
        journeys?: JourneyRow[];
        appeals?: DossierAppeal[];
        appealAccess?: AppealAccess;
        views?: ResultView[];
    },
) {
    const { t } = useTranslations();
    const { name } = usePage<{ name: string }>().props;
    const [busy, setBusy] = useState(false);
    const { student } = card;

    const download = async () => {
        setBusy(true);
        try {
            await downloadResultCard(t, card, name);
        } catch {
            toast.error(
                t(
                    'Could not prepare the download. Try again, or print the card.',
                ),
            );
        } finally {
            setBusy(false);
        }
    };

    return (
        <>
            <Head title={`${t('Result card')} · ${student.name}`} />
            <PrintPage
                title={t('First-month result card')}
                actions={
                    <Button
                        variant="outline"
                        onClick={download}
                        disabled={busy}
                        data-test="download"
                    >
                        {busy ? (
                            <LoaderCircle
                                className="size-4 animate-spin"
                                aria-hidden
                            />
                        ) : (
                            <Download className="size-4" aria-hidden />
                        )}
                        {t('Download the result')}
                    </Button>
                }
            >
                <ResultCard card={card} printable />
                <StudentDossier
                    journeys={card.journeys ?? []}
                    appeals={card.appeals ?? []}
                    appealAccess={card.appealAccess ?? null}
                    views={card.views ?? []}
                />
            </PrintPage>
        </>
    );
}
