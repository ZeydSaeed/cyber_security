import type { ComponentType, ReactNode, SVGProps } from 'react';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';

/** One of the nine tile colours of the design system (palette.json). */
export type Tile = 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9;

/**
 * Each tile colour's classes (owner's picture, 2026-11-20): a soft card
 * with a faint border, a solid round badge with a halo, a legend dot and
 * a bar segment. Written out whole so Tailwind finds them.
 */
export const tileClasses: Record<
    Tile,
    { card: string; badge: string; solid: string }
> = {
    1: {
        card: 'bg-tile-1-soft border-tile-1/30',
        badge: 'bg-tile-1 text-tile-1-foreground ring-tile-1/25',
        solid: 'bg-tile-1',
    },
    2: {
        card: 'bg-tile-2-soft border-tile-2/30',
        badge: 'bg-tile-2 text-tile-2-foreground ring-tile-2/25',
        solid: 'bg-tile-2',
    },
    3: {
        card: 'bg-tile-3-soft border-tile-3/30',
        badge: 'bg-tile-3 text-tile-3-foreground ring-tile-3/25',
        solid: 'bg-tile-3',
    },
    4: {
        card: 'bg-tile-4-soft border-tile-4/30',
        badge: 'bg-tile-4 text-tile-4-foreground ring-tile-4/25',
        solid: 'bg-tile-4',
    },
    5: {
        card: 'bg-tile-5-soft border-tile-5/30',
        badge: 'bg-tile-5 text-tile-5-foreground ring-tile-5/25',
        solid: 'bg-tile-5',
    },
    6: {
        card: 'bg-tile-6-soft border-tile-6/30',
        badge: 'bg-tile-6 text-tile-6-foreground ring-tile-6/25',
        solid: 'bg-tile-6',
    },
    7: {
        card: 'bg-tile-7-soft border-tile-7/30',
        badge: 'bg-tile-7 text-tile-7-foreground ring-tile-7/25',
        solid: 'bg-tile-7',
    },
    8: {
        card: 'bg-tile-8-soft border-tile-8/30',
        badge: 'bg-tile-8 text-tile-8-foreground ring-tile-8/25',
        solid: 'bg-tile-8',
    },
    9: {
        card: 'bg-tile-9-soft border-tile-9/30',
        badge: 'bg-tile-9 text-tile-9-foreground ring-tile-9/25',
        solid: 'bg-tile-9',
    },
};

type Icon = ComponentType<SVGProps<SVGSVGElement>>;

/**
 * The round badge: the picture in the tile's foreground on its solid
 * colour. The settings pictures draw details in the text colour; inside
 * the badge they take the badge's own.
 */
export function TileBadge({
    tile,
    icon: Glyph,
    size = 'md',
}: {
    tile: Tile;
    icon: Icon;
    size?: 'md' | 'lg';
}) {
    return (
        <span
            className={cn(
                'inline-flex shrink-0 items-center justify-center rounded-full ring-4 [&_.stroke-foreground]:stroke-current',
                size === 'lg' ? 'size-16' : 'size-12',
                tileClasses[tile].badge,
            )}
            aria-hidden
        >
            <Glyph className={size === 'lg' ? 'size-9' : 'size-6'} />
        </span>
    );
}

/** A card of the picture: badge, label, a large number, and a line under it. */
export function TileCard({
    tile,
    icon,
    label,
    value,
    footer,
    className,
    children,
}: {
    tile: Tile;
    icon: Icon;
    label: ReactNode;
    value?: ReactNode;
    footer?: ReactNode;
    className?: string;
    children?: ReactNode;
}) {
    return (
        <div
            className={cn(
                'relative flex h-full flex-col items-center gap-2 rounded-xl border p-4 text-center',
                tileClasses[tile].card,
                className,
            )}
        >
            <TileBadge tile={tile} icon={icon} />
            <span className="text-sm font-semibold text-foreground">
                {label}
            </span>
            {value !== undefined && value !== null && (
                <span className="text-2xl font-bold text-foreground tabular-nums">
                    {value}
                </span>
            )}
            {footer && (
                <span className="text-xs text-muted-foreground">{footer}</span>
            )}
            {children}
        </div>
    );
}

export type StatusItem = {
    key: string;
    label: string;
    count: number;
    tile: Tile;
    icon: Icon;
    /** Part of the bar and the legend (the parts add up to the whole). */
    inBar?: boolean;
};

/**
 * The picture's layout: a bar split by share, a legend with each share,
 * and a card per status. With nothing to count, the bar is empty.
 */
export function StatusSummary({
    items,
    title,
    testId,
}: {
    items: StatusItem[];
    title: string;
    testId?: string;
}) {
    const { t } = useTranslations();
    const parts = items.filter((item) => item.inBar);
    const total = parts.reduce((sum, item) => sum + item.count, 0);
    const percent = (count: number) =>
        total === 0 ? 0 : Math.round((count / total) * 100);

    return (
        <section
            className="grid gap-4 rounded-lg border bg-card p-4"
            aria-label={title}
            data-test={testId}
        >
            <div
                className="flex h-3 w-full gap-0.5 overflow-hidden rounded-full bg-muted"
                role="img"
                aria-label={parts
                    .map((item) => `${item.label} ${percent(item.count)}%`)
                    .join('، ')}
            >
                {parts
                    .filter((item) => item.count > 0)
                    .map((item) => (
                        <span
                            key={item.key}
                            className={cn(
                                'h-full',
                                tileClasses[item.tile].solid,
                            )}
                            style={{ width: `${(item.count / total) * 100}%` }}
                        />
                    ))}
            </div>

            {/* The legend first: at the start (right in Arabic), as in the picture. */}
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start">
                <ul className="grid shrink-0 gap-2 text-sm lg:w-64">
                    {parts.map((item) => (
                        <li key={item.key} className="flex items-center gap-2">
                            <span
                                className={cn(
                                    'size-3 shrink-0 rounded-full',
                                    tileClasses[item.tile].solid,
                                )}
                                aria-hidden
                            />
                            <span className="font-medium">{item.label}</span>
                            <span className="text-muted-foreground tabular-nums">
                                · {percent(item.count)}%
                            </span>
                        </li>
                    ))}
                    <li className="text-muted-foreground">
                        {t('Total: :count', { count: String(total) })}
                    </li>
                </ul>

                <ul className="grid flex-1 grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
                    {items.map((item) => (
                        <li key={item.key} data-test={`status-${item.key}`}>
                            <TileCard
                                tile={item.tile}
                                icon={item.icon}
                                label={item.label}
                                value={item.count}
                            />
                        </li>
                    ))}
                </ul>
            </div>
        </section>
    );
}
