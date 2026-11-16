import { Search, X } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import type { ReactNode } from 'react';
import { toolbarControls, toolButton } from '@/components/page-toolbar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';

export type FilterDef = {
    key: string;
    label: string;
    options: { value: string; label: string }[];
};

const ALL = '__all__';

/**
 * Search (sent after a short pause in typing) and filters for a
 * server-side table, plus room for the column and view menus. `compact`
 * fits it in the top toolbar (PageToolbar): one line of small controls,
 * each filter's title inside its field.
 */
export default function TableToolbar({
    search,
    filters,
    values,
    onSearch,
    onFilter,
    onClear,
    children,
    compact = false,
}: {
    search: string;
    filters: FilterDef[];
    values: Record<string, string>;
    onSearch: (search: string) => void;
    onFilter: (key: string, value: string | null) => void;
    onClear: () => void;
    children?: ReactNode;
    compact?: boolean;
}) {
    const { t } = useTranslations();
    const searchId = useId();
    const [text, setText] = useState(search);
    const [followed, setFollowed] = useState(search);

    // Follows navigation (a saved view, clearing filters), during render:
    // synced in an effect, one render would pair the old text with the new
    // search and could send the old text again (suspected in one WebKit
    // failure on CI, Phase 8).
    if (search !== followed) {
        setFollowed(search);
        setText(search);
    }

    useEffect(() => {
        if (text === search) {
            return;
        }

        const timer = window.setTimeout(() => onSearch(text), 350);

        return () => window.clearTimeout(timer);
    }, [text, search, onSearch]);

    const active = search !== '' || Object.keys(values).length > 0;

    const labelClass = compact ? 'sr-only' : 'text-sm font-medium';

    return (
        <div
            className={cn(
                'flex flex-wrap gap-3',
                compact ? `items-center gap-2 ${toolbarControls}` : 'items-end',
            )}
        >
            <div className={compact ? 'grid' : 'grid gap-1.5'}>
                <label htmlFor={searchId} className={labelClass}>
                    {t('Search')}
                </label>
                <div className="relative">
                    <Search
                        className="pointer-events-none absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden
                    />
                    <Input
                        id={searchId}
                        type="search"
                        value={text}
                        onChange={(event) => setText(event.target.value)}
                        className={
                            compact
                                ? 'h-8 w-56 bg-card ps-8 sm:w-80'
                                : 'w-56 ps-8'
                        }
                        placeholder={compact ? t('Search') : undefined}
                        data-test="table-search"
                        // "/" moves focus here (keyboard shortcuts, #17).
                        data-shortcut-search
                    />
                </div>
            </div>

            {filters.map((filter) => (
                <div
                    key={filter.key}
                    className={compact ? 'grid' : 'grid gap-1.5'}
                >
                    <span
                        id={`${searchId}-${filter.key}`}
                        className={labelClass}
                    >
                        {filter.label}
                    </span>
                    <Select
                        value={values[filter.key] ?? ALL}
                        onValueChange={(value) =>
                            onFilter(filter.key, value === ALL ? null : value)
                        }
                    >
                        <SelectTrigger
                            size={compact ? 'sm' : 'default'}
                            className={
                                compact ? 'w-auto min-w-40 bg-card' : 'w-44'
                            }
                            aria-labelledby={`${searchId}-${filter.key}`}
                            data-test={`filter-${filter.key}`}
                        >
                            {/* In the toolbar the title is inside the field,
                                "Stage: All" (owner, 2026-11-16); the label
                                outside is for screen readers only. */}
                            {compact && (
                                <span
                                    aria-hidden
                                    className="font-semibold text-muted-foreground"
                                >
                                    {filter.label}:
                                </span>
                            )}
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>{t('All')}</SelectItem>
                            {filter.options.map((option) => (
                                <SelectItem
                                    key={option.value}
                                    value={option.value}
                                >
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            ))}

            {active && (
                <Button
                    variant={compact ? 'outline' : 'ghost'}
                    size={compact ? 'sm' : 'default'}
                    // In the toolbar, a toolbar button: the page's own text
                    // colour would not read on the toolbar in dark mode.
                    className={compact ? toolButton : undefined}
                    onClick={onClear}
                >
                    <X aria-hidden />
                    {t('Clear filters')}
                </Button>
            )}

            {children && (
                <div className="ms-auto flex items-center gap-2">
                    {children}
                </div>
            )}
        </div>
    );
}
