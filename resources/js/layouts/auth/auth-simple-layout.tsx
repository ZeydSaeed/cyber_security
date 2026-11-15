import { Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import LocaleSwitcher from '@/components/locale-switcher';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { name, tagline } = usePage().props;

    return (
        <div className="relative flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
            <div className="absolute end-4 top-4">
                <LocaleSwitcher />
            </div>
            <div className="w-full max-w-sm">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-4">
                        <Link
                            href={home()}
                            className="flex flex-col items-center gap-2 font-medium"
                        >
                            <div className="mb-1 flex size-24 items-center justify-center">
                                <AppLogoIcon className="size-24" />
                            </div>
                            <span className="sr-only">{title}</span>
                        </Link>

                        {/* The department's name and academic line (owner's
                            request, 2026-11-15). */}
                        <div className="space-y-1 text-center">
                            <p
                                className="text-2xl font-bold"
                                data-test="app-name"
                            >
                                {name}
                            </p>
                            <p
                                className="text-sm text-muted-foreground"
                                lang="en"
                                dir="ltr"
                                data-test="tagline"
                            >
                                {tagline}
                            </p>
                        </div>

                        <div className="space-y-2 text-center">
                            <h1 className="text-xl font-medium">{title}</h1>
                            <p className="text-center text-sm text-muted-foreground">
                                {description}
                            </p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
