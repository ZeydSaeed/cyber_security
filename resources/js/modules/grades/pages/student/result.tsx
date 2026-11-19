import { Head } from '@inertiajs/react';
import { Clock, Printer } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import ResultCard from '../../components/result-card';
import { placeLabelFull } from '../../place';
import type { ResultCardData } from '../../types';

/**
 * A student's own first-month result (ADR-0034), once the owner has
 * published the results; until then, only that it is not published yet.
 */
export default function StudentResult({
    published,
    card,
}: {
    published: boolean;
    /** Only what the owner chose to show (ADR-0039). */
    card: ResultCardData | null;
}) {
    const { t } = useTranslations();

    return (
        <>
            <Head title={t('Your result')} />
            <header className="mb-6 flex flex-wrap items-center gap-3">
                <div className="flex-1">
                    <h1 className="text-xl font-semibold">
                        {t('First-month result card')}
                    </h1>
                    {card && (
                        <p className="text-sm text-muted-foreground">
                            {placeLabelFull(
                                t,
                                card.stage,
                                card.section,
                                card.divided,
                            )}
                        </p>
                    )}
                </div>
                {card && (
                    <Button
                        variant="outline"
                        onClick={() => window.print()}
                        className="print:hidden"
                    >
                        <Printer className="size-4" aria-hidden />
                        {t('Print')}
                    </Button>
                )}
            </header>

            {published && card ? (
                <ResultCard card={card} showName={false} />
            ) : (
                <p
                    role="status"
                    className="flex items-start gap-2 rounded-md border bg-muted p-4 text-sm"
                    data-test="not-published"
                >
                    <Clock className="mt-0.5 size-4 shrink-0" aria-hidden />
                    {t(
                        'The results are not published yet. Come back later with the same code.',
                    )}
                </p>
            )}
        </>
    );
}
