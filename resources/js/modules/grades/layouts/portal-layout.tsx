import { Link, usePage } from '@inertiajs/react';
import { LogOut } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import type { PortalProps } from '../types';

/**
 * The teachers' and students' pages (ADR-0034): no sidebar and nothing of
 * the users' application, only who is signed in and signing out (no
 * language button, owner's request of 2026-11-15). Built for a phone
 * first; black bold text on a light page.
 */
export default function PortalLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    const { t } = useTranslations();
    const { portal } = usePage<PortalProps>().props;

    return (
        <div className="flex min-h-svh flex-col bg-background">
            <header className="sticky top-0 z-10 border-b bg-background/95 backdrop-blur print:hidden">
                <div className="mx-auto flex h-14 max-w-3xl items-center gap-3 px-4">
                    <AppLogoIcon className="size-8 shrink-0" />
                    <p
                        className="min-w-0 flex-1 truncate text-base font-bold"
                        data-test="portal-name"
                    >
                        <bdi>{portal?.name}</bdi>
                    </p>
                    {portal && (
                        <Button variant="ghost" size="sm" asChild>
                            <Link
                                href={portal.logout}
                                method="post"
                                as="button"
                                data-test="portal-logout"
                            >
                                <LogOut className="size-4" aria-hidden />
                                <span className="sr-only sm:not-sr-only">
                                    {t('Sign out')}
                                </span>
                            </Link>
                        </Button>
                    )}
                </div>
            </header>
            <main className="mx-auto w-full max-w-3xl flex-1 px-4 py-6 font-semibold text-ink">
                {children}
            </main>
        </div>
    );
}
