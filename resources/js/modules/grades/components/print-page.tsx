import { usePage } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import type { ReactNode } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';

/**
 * A page made to be printed (ADR-0034): no application around it, the
 * department's seal and name at the top, a print button that does not
 * print, and the page's own direction (Arabic prints right to left).
 */
export default function PrintPage({
    title,
    subtitle,
    children,
}: {
    title: string;
    subtitle: string;
    children: ReactNode;
}) {
    const { t } = useTranslations();
    const { name } = usePage<{ name: string }>().props;

    return (
        <div className="mx-auto max-w-5xl bg-background p-6 text-foreground print:max-w-none print:p-0">
            <div className="mb-6 flex justify-end print:hidden">
                <Button onClick={() => window.print()} data-test="print">
                    <Printer className="size-4" aria-hidden />
                    {t('Print')}
                </Button>
            </div>
            <header className="mb-6 flex items-center gap-4 border-b pb-4">
                <AppLogoIcon className="size-16" />
                <div>
                    <p className="text-sm text-muted-foreground">{name}</p>
                    <h1 className="text-xl font-semibold">{title}</h1>
                    <p className="text-sm">{subtitle}</p>
                </div>
            </header>
            <main>{children}</main>
        </div>
    );
}
