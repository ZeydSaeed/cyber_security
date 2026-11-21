/**
 * Row actions on the students' and teachers' pages (owner's request,
 * 2026-11-20): an icon only, in a colour that says what it does, with no
 * background or border. The action's word is the button's accessible name
 * and hover hint, so colour is never the only cue. Status tokens only
 * (COLOR-DESIGN-SYSTEM).
 *
 * Both pages use the same icons in the same order: edit (Pencil), a first
 * code (KeyRound) or a new one (RefreshCw), forget the devices (MonitorX),
 * stop or allow signing in (Lock / LockOpen: what a click does), the
 * appeal (students only), delete (Trash2).
 */

/** The row's actions, on one horizontal line. */
export const rowActions =
    'flex flex-nowrap items-center gap-1 whitespace-nowrap';

export const rowAction =
    'size-8 bg-transparent shadow-none hover:bg-transparent hover:opacity-75 dark:hover:bg-transparent [&_svg:not([class*=size-])]:size-[1.15rem]';

export const rowActionTone = {
    info: 'text-info-text hover:text-info-text',
    success: 'text-success-text hover:text-success-text',
    warning: 'text-warning-text hover:text-warning-text',
    destructive: 'text-destructive-text hover:text-destructive-text',
} as const;
