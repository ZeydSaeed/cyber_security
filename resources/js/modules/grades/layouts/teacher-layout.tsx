import { Link, usePage } from '@inertiajs/react';
import { LogOut } from 'lucide-react';
import TeacherSessionController from '@/actions/App/Modules/Grades/Http/TeacherSessionController';
import AppLogoIcon from '@/components/app-logo-icon';
import LocaleSwitcher from '@/components/locale-switcher';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import type { TeacherProps } from '../types';

/**
 * The teachers' pages (ADR-0034): no sidebar and nothing of the users'
 * application, only who is signed in, the language and signing out. Built
 * for a phone first.
 */
export default function TeacherLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    const { t } = useTranslations();
    const { teacher } = usePage<TeacherProps>().props;

    return (
        <div className="flex min-h-svh flex-col bg-background">
            <header className="sticky top-0 z-10 border-b bg-background/95 backdrop-blur">
                <div className="mx-auto flex h-14 max-w-3xl items-center gap-3 px-4">
                    <AppLogoIcon className="size-8 shrink-0" />
                    <p
                        className="min-w-0 flex-1 truncate text-sm font-medium"
                        data-test="teacher-name"
                    >
                        {teacher?.name}
                    </p>
                    <LocaleSwitcher />
                    <Button variant="ghost" size="sm" asChild>
                        <Link
                            href={TeacherSessionController.destroy.url()}
                            method="post"
                            as="button"
                            data-test="teacher-logout"
                        >
                            <LogOut className="size-4" aria-hidden />
                            <span className="sr-only sm:not-sr-only">
                                {t('Sign out')}
                            </span>
                        </Link>
                    </Button>
                </div>
            </header>
            <main className="mx-auto w-full max-w-3xl flex-1 px-4 py-6">
                {children}
            </main>
        </div>
    );
}
