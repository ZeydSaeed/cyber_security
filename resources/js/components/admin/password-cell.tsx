import { Eye, EyeOff } from 'lucide-react';
import { useState } from 'react';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import { useTranslations } from '@/hooks/use-translations';
import { csrfToken } from '@/lib/csrf';

export type PasswordState = 'set' | 'changed' | 'unknown';

/**
 * A password in the users table (owner's request, 2026-11-18): hidden
 * until the eye is pressed; then fetched from the server, which audits
 * each showing, and hidden again on the next press. Never kept beyond
 * this cell.
 */
export default function PasswordCell({
    userId,
    userName,
    state,
}: {
    userId: number;
    userName: string;
    state: PasswordState;
}) {
    const { t } = useTranslations();
    const [password, setPassword] = useState<string | null>(null);
    const [current, setCurrent] = useState<PasswordState>(state);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);

    if (current === 'changed') {
        return (
            <span className="text-muted-foreground">
                {t('Changed by the user')}
            </span>
        );
    }

    if (current === 'unknown') {
        return <span className="text-muted-foreground">{t('Unknown')}</span>;
    }

    const toggle = () => {
        if (password !== null) {
            setPassword(null);

            return;
        }

        setLoading(true);
        setFailed(false);
        fetch(UserController.password.url(userId), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error(`Password not shown (${response.status}).`);
                }

                const body = (await response.json()) as {
                    state: PasswordState;
                    password: string | null;
                };
                setCurrent(body.state);
                setPassword(body.password);
            })
            .catch(() => setFailed(true))
            .finally(() => setLoading(false));
    };

    const shown = password !== null;

    return (
        <span className="inline-flex items-center gap-2">
            <span
                dir="ltr"
                className="font-mono"
                data-test="password-value"
                aria-live="polite"
            >
                {shown ? password : '••••••••'}
            </span>
            <button
                type="button"
                onClick={toggle}
                disabled={loading}
                className="rounded-md p-1 text-muted-foreground hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50"
                aria-label={
                    shown
                        ? t('Hide the password of :name', { name: userName })
                        : t('Show the password of :name', { name: userName })
                }
                aria-pressed={shown}
                data-test="toggle-password"
            >
                {shown ? (
                    <EyeOff className="size-4" aria-hidden />
                ) : (
                    <Eye className="size-4" aria-hidden />
                )}
            </button>
            {failed && (
                <span role="alert" className="text-xs text-destructive-text">
                    {t('Could not show the password.')}
                </span>
            )}
        </span>
    );
}
