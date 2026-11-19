import type { VisitOptions } from '@inertiajs/core';
import { router } from '@inertiajs/react';

/**
 * Smooth navigation between pages.
 *
 * - Moving to another page (a GET to a different path that replaces the
 *   page) cross-fades through the View Transitions API, where the browser
 *   has it. Visits that stay on the page — filters, sorting, pagination,
 *   tabs on the same path, partial reloads, forms — keep updating in place,
 *   so a table never flashes while it is being worked on.
 * - Those who ask for less motion get none (app.css turns the transition
 *   off under prefers-reduced-motion).
 */
export function visitOptions(
    href: string,
    options: VisitOptions,
): Partial<VisitOptions> {
    if (options.viewTransition !== undefined) {
        return {};
    }

    const method = (options.method ?? 'get').toLowerCase();

    if (
        method !== 'get' ||
        options.preserveState === true ||
        options.preserveScroll === true ||
        options.async === true ||
        (options.only?.length ?? 0) > 0 ||
        (options.except?.length ?? 0) > 0
    ) {
        return {};
    }

    let target: URL;

    try {
        target = new URL(href, window.location.href);
    } catch {
        return {};
    }

    if (
        target.origin !== window.location.origin ||
        target.pathname === window.location.pathname
    ) {
        return {};
    }

    return { viewTransition: true };
}

/**
 * When a link prefetches a page's data (on hover), fetch that page's code
 * too, so the click finds both ready and the page shows at once.
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
