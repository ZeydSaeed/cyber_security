import { usePage } from '@inertiajs/react';

import AppLogoIcon from '@/components/app-logo-icon';

/**
 * The seal, the name and the department's academic line under it (owner's
 * request, 2026-11-15).
 */
export default function AppLogo() {
    const { name, tagline } = usePage().props;

    return (
        <>
            <AppLogoIcon className="size-9 shrink-0" />
            <div className="ms-1 grid flex-1 text-start">
                <span className="truncate text-sm leading-tight font-bold">
                    {name}
                </span>
                <span
                    className="line-clamp-3 text-[0.65rem] leading-snug font-normal whitespace-normal opacity-85"
                    lang="en"
                    dir="ltr"
                    data-test="tagline"
                >
                    {tagline}
                </span>
            </div>
        </>
    );
}
