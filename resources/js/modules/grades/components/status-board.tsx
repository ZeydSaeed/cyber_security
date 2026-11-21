import type { LucideIcon } from 'lucide-react';
import { useId } from 'react';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';

/**
 * A state's colour (palette roles status-*, owner's request 2026-11-20):
 * good, waiting, bad, information and final. Checked for colour-blind
 * separation in the bar's orders (good, wait, bad; final first; info
 * between good and wait) and for contrast in pairs.json. Written out whole
 * so Tailwind finds them.
 */
export type Tone = 'good' | 'wait' | 'bad' | 'info' | 'final';

const TONES: Record<
    Tone,
    { card: string; icon: string; dot: string; bar: string }
> = {
    good: {
        card: 'bg-status-good-soft border-status-good/30',
        icon: 'bg-status-good text-status-good-foreground ring-status-good/25',
        dot: 'bg-status-good',
        bar: 'bg-status-good',
    },
    wait: {
        card: 'bg-status-wait-soft border-status-wait/30',
        icon: 'bg-status-wait text-status-wait-foreground ring-status-wait/25',
        dot: 'bg-status-wait',
        bar: 'bg-status-wait',
    },
    bad: {
        card: 'bg-status-bad-soft border-status-bad/30',
        icon: 'bg-status-bad text-status-bad-foreground ring-status-bad/25',
        dot: 'bg-status-bad',
        bar: 'bg-status-bad',
    },
    info: {
        card: 'bg-status-info-soft border-status-info/30',
        icon: 'bg-status-info text-status-info-foreground ring-status-info/25',
        dot: 'bg-status-info',
        bar: 'bg-status-info',
    },
    final: {
        card: 'bg-status-final-soft border-status-final/30',
        icon: 'bg-status-final text-status-final-foreground ring-status-final/25',
        dot: 'bg-status-final',
        bar: 'bg-status-final',
    },
};

export type BoardItem = {
    key: string;
    label: string;
    /** The number on the card (and the segment's size when in the bar). */
    value: number;
    /** Shown on the card instead of the number, e.g. a rate. */
    display?: string;
    hint?: string;
    tone: Tone;
    icon: LucideIcon;
    /** Part of the whole: drawn in the bar and the legend. */
    inBar?: boolean;
};

/**
 * A board of states (owner's request, 2026-11-20, after the department's
 * own status page): a bar split into the states' shares, a legend with
 * each share in words, and a card per state with its icon, name and
 * number. The numbers are always written, so the colours never carry the
 * meaning alone; the bar has a text equivalent for screen readers.
 */
export default function StatusBoard({
    title,
    items,
    test,
}: {
    title: string;
    items: BoardItem[];
    test?: string;
}) {
    const { t } = useTranslations();
    const id = useId();
    const parts = items.filter((item) => item.inBar);
    const total = parts.reduce((sum, item) => sum + item.value, 0);
    const share = (value: number) =>
        total === 0 ? 0 : Math.round((value * 1000) / total) / 10;
    const summary = parts
        .map((item) => `${item.label}: ${item.value} (${share(item.value)}%)`)
        .join('، ');

    return (
        <section
            aria-labelledby={`${id}-title`}
            className="flex flex-col gap-5 rounded-xl border bg-card p-5"
            data-test={test}
        >
            <h2 id={`${id}-title`} className="font-semibold">
                {title}
            </h2>

            {parts.length > 0 && (
                <div
                    role="img"
                    aria-label={
                        total === 0 ? t('Nothing to count yet.') : summary
                    }
                    className="flex h-3 w-full gap-0.5 overflow-hidden rounded-full bg-muted"
                    data-test="status-bar"
                >
                    {total > 0 &&
                        parts
                            .filter((item) => item.value > 0)
                            .map((item) => (
                                <span
                                    key={item.key}
                                    title={`${item.label}: ${item.value} (${share(item.value)}%)`}
                                    className={cn(
                                        'h-full first:rounded-s-full last:rounded-e-full',
                                        TONES[item.tone].bar,
                                    )}
                                    style={{
                                        flexGrow: item.value,
                                        flexBasis: 0,
                                    }}
                                />
                            ))}
                </div>
            )}

            <div className="flex flex-col gap-5 lg:flex-row lg:items-start">
                {parts.length > 0 && (
                    <ul
                        className="flex shrink-0 flex-col gap-2.5 text-sm lg:w-56"
                        data-test="status-legend"
                    >
                        {parts.map((item) => (
                            <li
                                key={item.key}
                                className="flex items-center gap-2"
                            >
                                <span
                                    aria-hidden
                                    className={cn(
                                        'size-3 shrink-0 rounded-full',
                                        TONES[item.tone].dot,
                                    )}
                                />
                                <span className="font-medium">
                                    {item.label}
                                </span>
                                <span className="text-muted-foreground tabular-nums">
                                    · {share(item.value)}%
                                </span>
                            </li>
                        ))}
                    </ul>
                )}

                <ul className="flex flex-1 flex-wrap justify-center gap-4">
                    {items.map((item) => (
                        <li
                            key={item.key}
                            className={cn(
                                'flex w-40 flex-col items-center gap-2 rounded-xl border p-4 text-center sm:w-44',
                                TONES[item.tone].card,
                            )}
                            data-test={`status-card-${item.key}`}
                        >
                            <span
                                aria-hidden
                                className={cn(
                                    'grid size-12 place-items-center rounded-full ring-4',
                                    TONES[item.tone].icon,
                                )}
                            >
                                <item.icon className="size-5" />
                            </span>
                            <span className="text-sm font-medium text-foreground">
                                {item.label}
                            </span>
                            <span className="text-2xl font-bold text-foreground tabular-nums">
                                {item.display ?? item.value}
                            </span>
                            {item.hint && (
                                <span className="text-xs text-muted-foreground">
                                    {item.hint}
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            </div>
        </section>
    );
}
