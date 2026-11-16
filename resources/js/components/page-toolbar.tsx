import { createContext, useContext, useState } from 'react';
import type { ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { cn } from '@/lib/utils';

type Slot = HTMLElement | null;

const SlotContext = createContext<{
    slot: Slot;
    setSlot: (slot: Slot) => void;
}>({ slot: null, setSlot: () => {} });

/** Around the layout: the toolbar's slot and the pages that fill it. */
export function PageToolbarProvider({ children }: { children: ReactNode }) {
    const [slot, setSlot] = useState<Slot>(null);

    return (
        <SlotContext.Provider value={{ slot, setSlot }}>
            {children}
        </SlotContext.Provider>
    );
}

/** In the top toolbar: where a page's title and controls appear. */
export function PageToolbarSlot({ className }: { className?: string }) {
    const { setSlot } = useContext(SlotContext);

    return (
        <div
            ref={setSlot}
            className={cn(
                // Sized by its content: on a narrow screen it moves to a line of
                // its own, where its controls wrap.
                'flex min-w-0 flex-[1_1_auto] flex-wrap items-center gap-x-3 gap-y-1',
                className,
            )}
            data-slot="page-toolbar"
        />
    );
}

/** Buttons in the toolbar's text colour, with icons in the toolbar colour. */
export const toolButton =
    'border-toolbar-foreground bg-toolbar-foreground text-toolbar hover:bg-toolbar-foreground/90 hover:text-toolbar dark:border-toolbar-foreground dark:bg-toolbar-foreground dark:hover:bg-toolbar-foreground/90';

/**
 * Controls inside the toolbar take the page's own text colours back: the
 * toolbar sets foreground and muted to its own colour for its text, which
 * would not read on a field's card background.
 */
export const toolbarControls =
    '[--color-foreground:var(--foreground)] [--color-muted-foreground:var(--muted-foreground)]';

/**
 * A page's title, and optionally its controls (search, filters, actions),
 * shown in the top toolbar (owner's layout, 2026-11-16). The title is the
 * page's h1. Rendered nowhere until the toolbar is on screen.
 */
export default function PageToolbar({
    title,
    children,
}: {
    title: string;
    children?: ReactNode;
}) {
    const { slot } = useContext(SlotContext);

    if (slot === null) {
        return null;
    }

    return createPortal(
        <>
            <h1 className="truncate text-base font-bold" data-test="page-title">
                {title}
            </h1>
            {children && (
                <div className="flex max-w-full min-w-0 flex-wrap items-center gap-2">
                    {children}
                </div>
            )}
        </>,
        slot,
    );
}
