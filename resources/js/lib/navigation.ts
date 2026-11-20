import { router } from '@inertiajs/react';

/**
 * When a link prefetches a page's data (on hover), fetch that page's code
 * too, so the click finds both ready and the page shows at once.
 *
 * Page-to-page view transitions were tried here and removed: they made
 * every visit slower (docs/evidence/ui-navigation/2026-11-20-before-after).
 */
export function warmPageChunks(load: (component: string) => void): void {
    router.on('prefetched', (event) => {
        const data: unknown = event.detail.response?.data;
        let component: unknown;

        if (typeof data === 'string') {
            try {
                component = (JSON.parse(data) as { component?: unknown })
                    .component;
            } catch {
                return;
            }
        } else if (data && typeof data === 'object') {
            component = (data as { component?: unknown }).component;
        }

        if (typeof component === 'string') {
            load(component);
        }
    });
}
