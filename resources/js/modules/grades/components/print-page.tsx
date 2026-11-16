import { usePage } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import type { ReactNode } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';

/**
 * A page made to be printed (ADR-0034): no application around it, and the
 * page's own direction (Arabic prints right to left). The header has three
 * parts (owner's request, 2026-11-16): the department's seal with its name
 * under it at the start, the title in the middle, and the print button (and
 * any other actions) at the end. The actions keep their space when printed,
 * so the title stays centred on paper too.
 */
export default function PrintPage({
    title,
    subtitle,
    actions,
    children,
}: {
    title: string;
    subtitle?: string;
    /** Further buttons beside Print (e.g. Download). */
    actions?: ReactNode;
    children: ReactNode;
}) {
    const { t } = useTranslations();
    const { name } = usePage<{ name: string }>().props;

    return (
        <div className="mx-auto max-w-5xl bg-background p-6 text-foreground print:max-w-none print:p-0">
            <header className="mb-6 grid grid-cols-[1fr_auto_1fr] items-center gap-4 border-b pb-4">
                <div className="flex flex-col items-center justify-self-start text-center">
                    <AppLogoIcon className="size-16" />
                    <p
                        className="mt-1 text-sm font-medium"
                        data-test="print-name"
                    >
                        {name}
                    </p>
                </div>
                <div className="text-center">
                    <h1 className="text-xl font-semibold">{title}</h1>
                    {subtitle && <p className="text-sm">{subtitle}</p>}
                </div>
                <div className="flex flex-wrap justify-end gap-2 justify-self-end print:invisible">
                    <Button onClick={() => window.print()} data-test="print">
                        <Printer className="size-4" aria-hidden />
                        {t('Print')}
                    </Button>
                    {actions}
                </div>
            </header>
            <main>{children}</main>
        </div>
    );
}
