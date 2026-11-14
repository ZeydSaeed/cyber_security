import { Lock } from 'lucide-react';
import { useTranslations } from '@/hooks/use-translations';

/** Shown while mark entry is closed: the marks can be read, not changed. */
export default function WindowNotice({ open }: { open: boolean }) {
    const { t } = useTranslations();

    if (open) {
        return null;
    }

    return (
        <p
            role="status"
            className="mb-6 flex items-start gap-2 rounded-md border border-warning bg-warning/15 p-3 text-sm"
            data-test="window-closed"
        >
            <Lock className="mt-0.5 size-4 shrink-0" aria-hidden />
            {t(
                'Mark entry is closed. You can read the marks but not change them.',
            )}
        </p>
    );
}
