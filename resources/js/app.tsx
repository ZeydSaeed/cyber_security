import { createInertiaApp } from '@inertiajs/react';
import { DirectionProvider } from '@radix-ui/react-direction';
import DeferredToaster from '@/components/deferred-toaster';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
// Listens from the start: dialogs in lazy chunks need the click that
// opened them (lib/opener.ts).
import '@/lib/opener';
import { warmPageChunks } from '@/lib/navigation';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import PortalLayout from '@/modules/grades/layouts/portal-layout';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Pages of the application and of each module (ADR-0033): a module page is
// named by its place, modules/NAME/pages/PAGE. Each page is its own chunk.
const pages = import.meta.glob<{ default: unknown }>([
    './pages/**/*.tsx',
    './modules/*/pages/**/*.tsx',
]);

const pagePath = (name: string) =>
    './' + (name.startsWith('modules/') ? name : 'pages/' + name) + '.tsx';

// A hovered link fetches the page's code with its data (lib/navigation.ts).
// A failed warm-up is harmless: the visit itself loads the page again.
warmPageChunks((name) => void pages[pagePath(name)]?.().catch(() => null));

void createInertiaApp({
    // No page-to-page view transitions: measured 2026-11-20, they showed
    // each page ~90 ms later and settled it ~300 ms later
    // (docs/evidence/ui-navigation/2026-11-20-before-after).
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: async (name) => {
        const load = pages[pagePath(name)];

        if (!load) {
            throw new Error(`Page not found: ${name}`);
        }

        return (await load()).default as never;
    },
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
            // Pages made to be printed: nothing of the application around them.
            case name.startsWith('modules/grades/pages/print/'):
                return null;
            case name.startsWith('auth/'):
            // The teachers' and students' code pages look like the users'
            // sign-in (ADR-0034).
            case name === 'modules/grades/pages/teacher/login':
            case name === 'modules/grades/pages/student/login':
                return AuthLayout;
            // Teachers and students are not users: none of the users' application.
            case name.startsWith('modules/grades/pages/teacher/'):
            case name.startsWith('modules/grades/pages/student/'):
                return PortalLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        // The server sets <html dir>; a language change reloads the page.
        const direction =
            document.documentElement.dir === 'rtl' ? 'rtl' : 'ltr';

        return (
            <DirectionProvider dir={direction}>
                <TooltipProvider delayDuration={0}>
                    {app}
                    <DeferredToaster
                        dir={direction}
                        position={
                            direction === 'rtl' ? 'bottom-left' : 'bottom-right'
                        }
                    />
                </TooltipProvider>
            </DirectionProvider>
        );
    },
    progress: {
        color: 'var(--primary)',
    },
});

// This will set light / dark mode on load...
initializeTheme();
