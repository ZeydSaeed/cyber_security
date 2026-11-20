import { Link, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, House, X } from 'lucide-react';
import { overview as gradesOverview } from '@/actions/App/Modules/Grades/Http/GradesAdminController';
import { Breadcrumbs } from '@/components/breadcrumbs';
import {
    PageToolbarNavSlot,
    PageToolbarSlot,
    toolButton,
} from '@/components/page-toolbar';
import { Button } from '@/components/ui/button';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

/**
 * The toolbar (owner's layout, 2026-11-15): low, in the L3 toolbar colour
 * with bold toolbar-foreground text. Muted and normal text inside it take
 * the toolbar's own colour. Since 2026-11-16 each page puts its title and
 * controls here (PageToolbar), the previous, next and home buttons sit at
 * the end (the left in Arabic), and the search button is gone: the command
 * search still opens with Ctrl+K. Since 2026-11-20 every page but the
 * dashboard has a Close button beside them, back to the dashboard (owner's
 * request); a page may add its own controls there (PageToolbarNav). The
 * toolbar grows to a second line when its
 * contents do not fit.
 */
export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { t } = useTranslations();
    // The dashboard is the control panel's cards (/admin with no tab open):
    // every other page has a Close button back to it (owner's request,
    // 2026-11-20); the dashboard itself has none.
    const dashboardHref = gradesOverview.url();
    const { url } = usePage();
    const [path, query = ''] = url.split('?');
    const onDashboard =
        path.replace(/\/+$/, '') === dashboardHref &&
        !new URLSearchParams(query).has('tab');

    return (
        <header className="flex min-h-10 shrink-0 flex-wrap items-center gap-x-2 gap-y-1 border-b border-sidebar-border/50 bg-toolbar bg-gradient-toolbar px-4 py-1.5 font-semibold text-toolbar-foreground [--color-foreground:var(--toolbar-foreground)] [--color-muted-foreground:var(--toolbar-foreground)] md:px-3">
            <div className="flex min-w-0 items-center gap-2">
                <SidebarTrigger className="-ms-1 size-7" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            <PageToolbarSlot />
            {/* Previous, next and home at the end: the left in Arabic. */}
            <nav
                aria-label={t('Page navigation')}
                className="ms-auto flex shrink-0 items-center gap-1"
            >
                <PageToolbarNavSlot />
                {!onDashboard && (
                    <Link
                        prefetch
                        href={dashboardHref}
                        className={cn(
                            'inline-flex h-7 items-center gap-1 rounded-md border px-2 text-sm font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            toolButton,
                        )}
                        data-test="close-page"
                    >
                        <X className="size-4" aria-hidden />
                        {t('Close')}
                    </Link>
                )}
                {/* Arrows mirrored in Arabic, as elsewhere (table-pagination.tsx). */}
                <Button
                    variant="outline"
                    size="icon"
                    className={`size-7 ${toolButton}`}
                    onClick={() => window.history.back()}
                    aria-label={t('Previous page')}
                    title={t('Previous page')}
                    data-test="history-back"
                >
                    <ArrowLeft className="rtl:-scale-x-100" aria-hidden />
                </Button>
                <Button
                    variant="outline"
                    size="icon"
                    className={`size-7 ${toolButton}`}
                    onClick={() => window.history.forward()}
                    aria-label={t('Next page')}
                    title={t('Next page')}
                    data-test="history-forward"
                >
                    <ArrowRight className="rtl:-scale-x-100" aria-hidden />
                </Button>
                <Button
                    variant="outline"
                    size="icon"
                    className={`size-7 ${toolButton}`}
                    asChild
                >
                    <Link
                        prefetch
                        href={dashboardHref}
                        aria-label={t('Home')}
                        title={t('Home')}
                        data-test="home"
                    >
                        <House aria-hidden />
                    </Link>
                </Button>
            </nav>
        </header>
    );
}
