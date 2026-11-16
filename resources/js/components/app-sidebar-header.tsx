import { Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, House, Search } from 'lucide-react';
import GradesAdminController from '@/actions/App/Modules/Grades/Http/GradesAdminController';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { OPEN_SEARCH_EVENT } from '@/components/keyboard-shortcuts';
import { PageToolbarSlot, toolButton } from '@/components/page-toolbar';
import { Button } from '@/components/ui/button';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useTranslations } from '@/hooks/use-translations';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

// Key names are the same in every language.
const SEARCH_KEYS = 'Ctrl K';

/**
 * The toolbar (owner's layout, 2026-11-15): low, in the L3 toolbar colour
 * with bold toolbar-foreground text; classic previous, next and home buttons; the search. Muted
 * and normal text inside it take the toolbar's own colour. Since
 * 2026-11-16 each page puts its title and controls here (PageToolbar); the
 * toolbar grows to a second line when they do not fit.
 */
export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { t } = useTranslations();

    return (
        <header className="flex min-h-10 shrink-0 flex-wrap items-center gap-x-2 gap-y-1 border-b border-sidebar-border/50 bg-toolbar bg-gradient-toolbar px-4 py-1.5 font-semibold text-toolbar-foreground [--color-foreground:var(--toolbar-foreground)] [--color-muted-foreground:var(--toolbar-foreground)] md:px-3">
            <div className="flex min-w-0 items-center gap-2">
                <SidebarTrigger className="-ms-1 size-7" />
                <nav
                    aria-label={t('Page navigation')}
                    className="flex items-center gap-1"
                >
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
                            href={GradesAdminController.overview()}
                            aria-label={t('Home')}
                            title={t('Home')}
                            data-test="home"
                        >
                            <House aria-hidden />
                        </Link>
                    </Button>
                </nav>
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            <PageToolbarSlot />
            {/* The search, for those who do not use Ctrl+K (#5). */}
            <Button
                variant="outline"
                size="sm"
                className={`ms-auto h-7 ${toolButton}`}
                onClick={() =>
                    window.dispatchEvent(new Event(OPEN_SEARCH_EVENT))
                }
                data-test="open-search"
            >
                <Search aria-hidden />
                <span className="sr-only sm:not-sr-only">{t('Search')}</span>
                <kbd
                    aria-hidden
                    dir="ltr"
                    className="hidden rounded border border-toolbar bg-toolbar px-1.5 font-mono text-xs text-toolbar-foreground md:inline"
                >
                    {SEARCH_KEYS}
                </kbd>
            </Button>
        </header>
    );
}
