import { router } from '@inertiajs/react';
import { lazy, Suspense, useEffect, useState } from 'react';
import type { ComponentProps } from 'react';
import type { Toaster as ToasterType } from '@/components/ui/sonner';
import type { FlashToast } from '@/types/ui';

// The toast library is not part of the base every page downloads
// (ADR-0024 budget): it loads once the page is idle, or at once when a
// toast arrives first. Sonner keeps toasts created before its Toaster
// mounts and shows them when it does, so none is lost meanwhile.
const Toaster = lazy(() =>
    import('@/components/ui/sonner').then((module) => ({
        default: module.Toaster,
    })),
);

const whenIdle = (work: () => void): (() => void) => {
    if (typeof window.requestIdleCallback === 'function') {
        const id = window.requestIdleCallback(work, { timeout: 2000 });

        return () => window.cancelIdleCallback(id);
    }

    const id = window.setTimeout(work, 200);

    return () => window.clearTimeout(id);
};

export default function DeferredToaster(
    props: ComponentProps<typeof ToasterType>,
) {
    const [mounted, setMounted] = useState(false);

    useEffect(() => whenIdle(() => setMounted(true)), []);

    // Listens from the first render, before the library has loaded.
    useEffect(
        () =>
            router.on('flash', (event) => {
                const flash = (event as CustomEvent).detail?.flash;
                const data = flash?.toast as FlashToast | undefined;

                if (!data) {
                    return;
                }

                setMounted(true);
                void import('sonner').then(({ toast }) =>
                    toast[data.type](data.message),
                );
            }),
        [],
    );

    return mounted ? (
        <Suspense fallback={null}>
            <Toaster {...props} />
        </Suspense>
    ) : null;
}
