import { usePage } from '@inertiajs/react';

import AppLogoIcon from '@/components/app-logo-icon';

/**
 * The seal and the name (owner's request, 2026-11-16: the department's
 * academic line under the name is no longer shown in the sidebar; the
 * sign-in pages and the welcome page keep it).
 */
export default function AppLogo() {
    const { name } = usePage().props;

    return (
        <>
            <AppLogoIcon className="size-9 shrink-0" />
            <div className="ms-1 grid flex-1 text-start">
                <span className="truncate text-sm leading-tight font-bold">
                    {name}
                </span>
            </div>
        </>
    );
}
