import { Head, usePage } from '@inertiajs/react';
import { Download, LoaderCircle } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import PrintPage from '../../components/print-page';
import ResultCard from '../../components/result-card';
import { downloadResultCard } from '../../download-card';
import type { ResultRow } from '../../types';

/**
 * One student's first-month result card, to print or to save as an image
 * on a phone or a computer (ADR-0034; owner's request, 2026-11-16).
 */
export default function PrintStudent(card: {
    stage: number;
    section: string;
    divided: boolean;
    pass_mark: number;
    subjects: { uuid: string; name: string }[];
    student: ResultRow;
    code: string | null;
}) {
    const { t } = useTranslations();
    const { name } = usePage<{ name: string }>().props;
    const [busy, setBusy] = useState(false);
    const { stage, section, divided, pass_mark, subjects, student } = card;

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
                <ResultCard
                    pass_mark={pass_mark}
                    subjects={subjects}
                    student={student}
                    place={{ stage, section, divided, code: card.code }}
                />
            </PrintPage>
        </>
    );
}
