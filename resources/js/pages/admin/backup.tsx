import { Head } from '@inertiajs/react';
import { DatabaseBackup, HardDriveDownload, ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';
import { csrfToken } from '@/lib/csrf';
import {
    index as backupIndex,
    store as backupStore,
} from '@/routes/admin/backup';

type Copy = { name: string; bytes: number; url: string };

/** The browser's "Save as" window (Chromium); missing elsewhere. */
type SavePicker = (options: {
    suggestedName: string;
    types: { description: string; accept: Record<string, string[]> }[];
}) => Promise<{ createWritable: () => Promise<WritableStream> }>;

function size(bytes: number): string {
    return bytes >= 1048576
        ? `${(bytes / 1048576).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

/**
 * A plain copy of the database (owner's request, 2026-11-21): the server
 * takes it, then the browser's "Save as" window lets the owner choose the
 * drive and folder. Where that window is not offered (Firefox, Safari),
 * the file goes to the browser's downloads. Each copy asks for the
 * person's password first.
 */
export default function Backup() {
    const { t } = useTranslations();
    const [asking, setAsking] = useState(false);
    const [password, setPassword] = useState('');
    const [passwordError, setPasswordError] = useState<string | undefined>();
    const [taking, setTaking] = useState(false);
    const [saving, setSaving] = useState(false);
    const [copy, setCopy] = useState<Copy | null>(null);

    const ask = () => {
        setPassword('');
        setPasswordError(undefined);
        setAsking(true);
    };

    const take = async (event: FormEvent) => {
        event.preventDefault();
        setTaking(true);
        setPasswordError(undefined);

        try {
            const response = await fetch(backupStore.url(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ password }),
            });

            if (response.status === 422) {
                const body = (await response.json()) as {
                    errors?: { password?: string[] };
                };
                setPasswordError(
                    body.errors?.password?.[0] ??
                        t('The password is incorrect.'),
                );

                return;
            }

            if (!response.ok) {
                throw new Error(`Copy not taken (${response.status}).`);
            }

            setAsking(false);
            setPassword('');
            setCopy((await response.json()) as Copy);
        } catch {
            setAsking(false);
            setPassword('');
            toast.error(t('The copy could not be taken.'));
        } finally {
            setTaking(false);
        }
    };

    const save = async () => {
        if (!copy) {
            return;
        }

        const picker = (
            window as unknown as { showSaveFilePicker?: SavePicker }
        ).showSaveFilePicker;

        if (!picker) {
            const link = document.createElement('a');
            link.href = copy.url;
            link.download = copy.name;
            document.body.appendChild(link);
            link.click();
            link.remove();
            setCopy(null);

            return;
        }

        let target: WritableStream;

        try {
            const handle = await picker({
                suggestedName: copy.name,
                types: [
                    {
                        description: t('Database copy (SQL)'),
                        accept: { 'application/sql': ['.sql'] },
                    },
                ],
            });
            target = await handle.createWritable();
        } catch {
            // The window was closed without choosing: the copy waits.
            return;
        }

        setSaving(true);

        try {
            const response = await fetch(copy.url, {
                credentials: 'same-origin',
            });

            if (!response.ok || !response.body) {
                throw new Error(`Copy not saved (${response.status}).`);
            }

            await response.body.pipeTo(target);
            toast.success(t('The copy was saved.'));
            setCopy(null);
        } catch {
            toast.error(t('The copy could not be saved. Take a new one.'));
            setCopy(null);
        } finally {
            setSaving(false);
        }
    };

    return (
        <>
            <Head title={t('Database backup')} />
            <div className="flex flex-col gap-6 p-4">
                <Heading
                    title={t('Database backup')}
                    description={t(
                        'A complete copy of the database, saved on this computer where you choose.',
                    )}
                />

                <section className="flex max-w-2xl flex-col gap-4 rounded-lg border bg-card p-5">
                    <div className="flex items-start gap-3">
                        <DatabaseBackup
                            className="mt-0.5 size-5 shrink-0 text-primary"
                            aria-hidden
                        />
                        <p className="text-sm">
                            {t(
                                'The copy holds every table: teachers, subjects, students, marks, appeals, users and the audit log. It is a plain SQL file that opens in any editor and is restored with psql.',
                            )}
                        </p>
                    </div>
                    <div className="flex items-start gap-3 rounded-md border border-warning bg-warning/15 p-3">
                        <ShieldAlert
                            className="mt-0.5 size-5 shrink-0 text-warning-text"
                            aria-hidden
                        />
                        <p className="text-sm text-warning-text">
                            {t(
                                'The copy is not encrypted: anyone who opens the file reads all the data. Keep it on a drive only you use.',
                            )}
                        </p>
                    </div>
                    <div>
                        <Button
                            onClick={ask}
                            disabled={taking}
                            data-test="backup-take"
                        >
                            <DatabaseBackup aria-hidden />
                            {t('Take a copy')}
                        </Button>
                    </div>
                </section>
            </div>

            <Dialog
                open={asking}
                onOpenChange={(open) => !open && !taking && setAsking(false)}
            >
                <DialogContent data-test="backup-password">
                    <form onSubmit={take} className="flex flex-col gap-4">
                        <DialogHeader>
                            <DialogTitle>{t('Confirm password')}</DialogTitle>
                            <DialogDescription>
                                {t(
                                    'Enter your password to take a copy of the database.',
                                )}
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2">
                            <Label htmlFor="backup-password">
                                {t('Password')}
                            </Label>
                            <PasswordInput
                                id="backup-password"
                                name="password"
                                autoComplete="current-password"
                                autoFocus
                                required
                                value={password}
                                onChange={(event) =>
                                    setPassword(event.target.value)
                                }
                                aria-invalid={passwordError !== undefined}
                                aria-describedby={
                                    passwordError
                                        ? 'backup-password-error'
                                        : undefined
                                }
                            />
                            <InputError
                                id="backup-password-error"
                                message={passwordError}
                            />
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setAsking(false)}
                                disabled={taking}
                            >
                                {t('Cancel')}
                            </Button>
                            <Button
                                type="submit"
                                disabled={taking || password === ''}
                                data-test="backup-confirm"
                            >
                                {taking ? (
                                    <Spinner />
                                ) : (
                                    <DatabaseBackup aria-hidden />
                                )}
                                {taking
                                    ? t('Taking the copy…')
                                    : t('Take a copy')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={copy !== null}
                onOpenChange={(open) => !open && !saving && setCopy(null)}
            >
                <DialogContent data-test="backup-ready">
                    <DialogHeader>
                        <DialogTitle>{t('The copy is ready')}</DialogTitle>
                        <DialogDescription>
                            {t(
                                'Choose the drive and the folder on this computer where it is saved.',
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    {copy && (
                        <p className="text-sm">
                            <bdi dir="ltr" className="font-mono">
                                {copy.name}
                            </bdi>{' '}
                            <span className="text-muted-foreground">
                                ({size(copy.bytes)})
                            </span>
                        </p>
                    )}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setCopy(null)}
                            disabled={saving}
                        >
                            {t('Cancel')}
                        </Button>
                        <Button
                            onClick={save}
                            disabled={saving}
                            data-test="backup-save"
                        >
                            {saving ? (
                                <Spinner />
                            ) : (
                                <HardDriveDownload aria-hidden />
                            )}
                            {t('Choose where to save')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

Backup.layout = {
    breadcrumbs: [{ title: 'Database backup', href: backupIndex() }],
};
