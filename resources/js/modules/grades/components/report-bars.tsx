import { useId, useState } from 'react';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';

export type ReportBar = {
    key: string;
    /** The row's name, already translated. */
    label: string;
    /** null: nothing to show yet (drawn empty, said in words). */
    value: number | null;
    /** A word beside the value, e.g. "lowest" (never colour alone). */
    note?: string;
    /** Shown on hover and in the table, e.g. "12 of 15". */
    detail?: string;
};

/**
 * Horizontal bars for one measure, 0 to 100 (owner's request,
 * 2026-11-20): rates of sections and subjects, whose names are too long
 * for a column chart's axis. One colour (chart-1), bars 12 px with a 4 px
 * rounded end, the value written beside each bar, a hairline at a chosen
 * reference (the pass rate, a mean), a hover/focus tooltip, and a table
 * view with every number. Bars grow from the reading start, so right to
 * left in Arabic.
 */
export default function ReportBars({
    title,
    description,
    bars,
    reference,
    referenceLabel,
    unit = '%',
}: {
    title: string;
    description?: string;
    bars: ReportBar[];
    /** A hairline at this value (0–100). */
    reference?: number | null;
    referenceLabel?: string;
    unit?: string;
}) {
    const { t } = useTranslations();
    const id = useId();
    const [table, setTable] = useState(false);
    const [active, setActive] = useState<string | null>(null);
    const show = (value: number | null) =>
        value === null ? t('No marks yet') : `${value}${unit}`;

    return (
        <figure className="flex flex-col gap-3" data-slot="report-bars">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <figcaption>
                    <h3 id={`${id}-title`} className="font-semibold">
                        {title}
                    </h3>
                    {description && (
                        <p className="text-sm text-muted-foreground">
                            {description}
                        </p>
                    )}
                </figcaption>
                <Button
                    variant="outline"
                    size="sm"
                    aria-pressed={table}
                    onClick={() => setTable(!table)}
                    data-test="chart-table-toggle"
                >
                    {t('Show as table')}
                </Button>
            </div>

            {table ? (
                <div className="overflow-x-auto rounded-md border border-grid-line bg-card">
                    <table className="w-full border-collapse border-hidden text-sm [&_td]:border [&_td]:border-grid-line [&_th]:border [&_th]:border-grid-line">
                        <caption className="sr-only">{title}</caption>
                        <thead className="bg-table-head text-table-head-foreground">
                            <tr>
                                <th
                                    scope="col"
                                    className="px-3 py-2 text-start font-medium"
                                >
                                    {t('Item')}
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-2 text-end font-medium"
                                >
                                    {t('Value')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {bars.map((bar) => (
                                <tr key={bar.key} className="border-t">
                                    <th
                                        scope="row"
                                        className="px-3 py-2 text-start font-normal"
                                    >
                                        {bar.label}
                                        {bar.note ? ` (${bar.note})` : ''}
                                    </th>
                                    <td className="px-3 py-2 text-end tabular-nums">
                                        {show(bar.value)}
                                        {bar.detail ? ` · ${bar.detail}` : ''}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <div
                    role="group"
                    aria-labelledby={`${id}-title`}
                    className="flex flex-col gap-2"
                >
                    {bars.map((bar) => (
                        <div
                            key={bar.key}
                            className="grid grid-cols-[minmax(5rem,9rem)_1fr] items-center gap-3 sm:grid-cols-[minmax(7rem,11rem)_1fr]"
                        >
                            <span
                                className="truncate text-sm"
                                title={bar.label}
                                aria-hidden
                            >
                                {bar.label}
                            </span>
                            <div
                                role="img"
                                tabIndex={0}
                                aria-label={`${bar.label}: ${show(bar.value)}${bar.note ? `، ${bar.note}` : ''}${bar.detail ? `، ${bar.detail}` : ''}`}
                                onMouseEnter={() => setActive(bar.key)}
                                onMouseLeave={() => setActive(null)}
                                onFocus={() => setActive(bar.key)}
                                onBlur={() => setActive(null)}
                                className="relative flex min-h-6 items-center gap-2 rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                data-bar={bar.key}
                            >
                                <div className="relative h-3 flex-1">
                                    {/* The track's baseline, recessive. */}
                                    <div className="absolute inset-y-0 start-0 w-px bg-border" />
                                    {bar.value !== null && bar.value > 0 && (
                                        <div
                                            className={cn(
                                                'absolute inset-y-0 start-0 rounded-e-[4px]',
                                                active === bar.key
                                                    ? 'bg-chart-1'
                                                    : 'bg-chart-1/85',
                                            )}
                                            style={{
                                                width: `${Math.min(100, bar.value)}%`,
                                            }}
                                        />
                                    )}
                                    {reference !== undefined &&
                                        reference !== null && (
                                            <div
                                                aria-hidden
                                                className="absolute -inset-y-1 w-px bg-foreground/60"
                                                style={{
                                                    insetInlineStart: `${reference}%`,
                                                }}
                                            />
                                        )}
                                </div>
                                <span
                                    className="w-20 shrink-0 text-sm tabular-nums"
                                    aria-hidden
                                >
                                    {show(bar.value)}
                                </span>
                                {bar.note && (
                                    <span
                                        className="shrink-0 rounded-sm border px-1.5 text-xs font-medium"
                                        aria-hidden
                                    >
                                        {bar.note}
                                    </span>
                                )}
                                {active === bar.key && bar.detail && (
                                    <span
                                        role="presentation"
                                        className="pointer-events-none absolute start-0 -top-7 z-10 rounded-md border bg-popover px-2 py-0.5 text-xs whitespace-nowrap text-popover-foreground shadow-sm"
                                    >
                                        {bar.label}: {show(bar.value)} ·{' '}
                                        {bar.detail}
                                    </span>
                                )}
                            </div>
                        </div>
                    ))}
                    {reference !== undefined &&
                        reference !== null &&
                        referenceLabel && (
                            <p className="flex items-center gap-2 text-xs text-muted-foreground">
                                <span
                                    aria-hidden
                                    className="inline-block h-3 w-px bg-foreground/60"
                                />
                                {referenceLabel}
                            </p>
                        )}
                </div>
            )}
        </figure>
    );
}
