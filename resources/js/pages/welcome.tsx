import { Head, Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import LocaleSwitcher from '@/components/locale-switcher';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import { dashboard, login } from '@/routes';

export default function Welcome() {
    const { auth, name, tagline } = usePage().props;
    const { t } = useTranslations();

    return (
        <>
            <Head title={t('Welcome')} />
            <div className="flex min-h-svh flex-col bg-background">
                <header className="flex items-center justify-end gap-2 p-4">
                    <LocaleSwitcher />
                </header>

                <main className="flex flex-1 flex-col items-center justify-center gap-6 px-4 pb-16 text-center">
                    <AppLogoIcon className="size-12 fill-current text-primary" />
                    <div className="space-y-1">
                        <h1 className="text-3xl font-bold">{name}</h1>
                        <p
                            className="text-sm text-muted-foreground"
                            lang="en"
                            dir="ltr"
                        >
                            {tagline}
                        </p>
                    </div>
                    <p className="max-w-md text-muted-foreground">
                        {t(
                            'First-month marks of the Cyber Security department, entered by the teachers and gathered into one result per student.',
                        )}
                    </p>

                    <Button asChild size="lg">
                        {auth.user ? (
                            <Link prefetch href={dashboard()}>
                                {t('Dashboard')}
                            </Link>
                        ) : (
                            <Link prefetch href={login()}>
                                {t('Log in')}
                            </Link>
                        )}
                    </Button>
                </main>
            </div>
        </>
    );
}
