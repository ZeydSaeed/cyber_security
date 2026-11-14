import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import StudentsAdminController from '@/actions/App/Modules/Grades/Http/StudentsAdminController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';

type Change = { from: string | number | null; to: string | number | null };

/**
 * What an import would change (ADR-0034), before anything is saved: new
 * students, changed ones (field by field), and refused rows with their
 * spreadsheet row number and the reason. Nothing is ever deleted.
 */
export default function ImportPreview({
    file,
    counts,
    creates,
    updates,
    rejected,
}: {
    file: string;
    counts: {
        created: number;
        updated: number;
        unchanged: number;
        rejected: number;
        not_in_file: number;
    };
    creates: {
        row: number;
        name: string;
        stage: number;
        section: string;
        number: string | null;
    }[];
    updates: { row: number; name: string; changes: Record<string, Change> }[];
    rejected: { row: number; reason: string }[];
}) {
    const { t } = useTranslations();
    const [busy, setBusy] = useState(false);
    const nothing = counts.created === 0 && counts.updated === 0;
    const fieldLabel: Record<string, string> = {
        full_name: t('Name'),
        stage: t('Stage'),
        section: t('Section'),
        student_number: t('Number'),
    };

    const figures = [
        [t('New students'), counts.created],
        [t('Changed'), counts.updated],
        [t('Unchanged'), counts.unchanged],
        [t('Not imported'), counts.rejected],
        [t('Already stored, not in the file'), counts.not_in_file],
    ] as const;

    return (
        <>
            <Head title={t('Import preview')} />
            <div className="flex max-w-4xl flex-col gap-6 p-4">
                <Heading
                    title={t('Import preview')}
                    description={t(
                        'From :file. Nothing is saved until you apply it.',
                        { file },
                    )}
                />

                <dl
                    className="grid grid-cols-2 gap-3 sm:grid-cols-5"
                    data-test="import-counts"
                >
                    {figures.map(([label, value]) => (
                        <div
                            key={label}
                            className="rounded-lg border bg-card p-3"
                        >
                            <dt className="text-sm text-muted-foreground">
                                {label}
                            </dt>
                            <dd className="text-2xl font-semibold tabular-nums">
                                {value}
                            </dd>
                        </div>
                    ))}
                </dl>

                <div className="flex flex-wrap gap-3">
                    <Button
                        disabled={busy || nothing}
                        onClick={() =>
                            router.post(
                                StudentsAdminController.apply.url(),
                                {},
                                {
                                    onStart: () => setBusy(true),
                                    onFinish: () => setBusy(false),
                                },
                            )
                        }
                        data-test="apply-import"
                    >
                        {busy && <Spinner />}
                        {t('Apply the import')}
                    </Button>
                    <Button
                        variant="ghost"
                        disabled={busy}
                        onClick={() =>
                            router.delete(StudentsAdminController.cancel.url())
                        }
                    >
                        {t('Cancel')}
                    </Button>
                    {nothing && (
                        <p className="self-center text-sm text-muted-foreground">
                            {t('This file changes nothing.')}
                        </p>
                    )}
                </div>

                {rejected.length > 0 && (
                    <section aria-labelledby="rejected">
                        <h2
                            id="rejected"
                            className="mb-2 font-semibold text-destructive"
                        >
                            {t('Rows not imported')}
                        </h2>
                        <ul
                            className="divide-y rounded-lg border bg-card text-sm"
                            data-test="rejected-rows"
                        >
                            {rejected.map((row) => (
                                <li key={row.row} className="flex gap-3 p-3">
                                    <span className="w-20 shrink-0 text-muted-foreground tabular-nums">
                                        {t('Row :row', {
                                            row: String(row.row),
                                        })}
                                    </span>
                                    <span>{row.reason}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {updates.length > 0 && (
                    <section aria-labelledby="updates">
                        <h2 id="updates" className="mb-2 font-semibold">
                            {t('Changed students')}
                        </h2>
                        <ul className="divide-y rounded-lg border bg-card text-sm">
                            {updates.map((row) => (
                                <li
                                    key={row.row}
                                    className="flex flex-col gap-1 p-3"
                                >
                                    <span className="font-medium" dir="auto">
                                        {row.name}
                                    </span>
                                    {Object.entries(row.changes).map(
                                        ([field, change]) => (
                                            <span
                                                key={field}
                                                className="text-muted-foreground"
                                            >
                                                {fieldLabel[field] ?? field}:{' '}
                                                {t('from :from to :to', {
                                                    from: String(
                                                        change.from ?? '—',
                                                    ),
                                                    to: String(
                                                        change.to ?? '—',
                                                    ),
                                                })}
                                            </span>
                                        ),
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {creates.length > 0 && (
                    <section aria-labelledby="creates">
                        <h2 id="creates" className="mb-2 font-semibold">
                            {t('New students')}
                        </h2>
                        <div className="overflow-x-auto rounded-lg border bg-card">
                            <table className="w-full text-sm">
                                <caption className="sr-only">
                                    {t('New students')}
                                </caption>
                                <thead>
                                    <tr className="text-muted-foreground">
                                        <th
                                            scope="col"
                                            className="p-2 text-start font-medium"
                                        >
                                            {t('Row')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="p-2 text-start font-medium"
                                        >
                                            {t('Name')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="p-2 text-start font-medium"
                                        >
                                            {t('Stage')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="p-2 text-start font-medium"
                                        >
                                            {t('Section')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="p-2 text-start font-medium"
                                        >
                                            {t('Number')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {creates.map((row) => (
                                        <tr key={row.row} className="border-t">
                                            <td className="p-2 tabular-nums">
                                                {row.row}
                                            </td>
                                            <td className="p-2">
                                                <bdi>{row.name}</bdi>
                                            </td>
                                            <td className="p-2">{row.stage}</td>
                                            <td className="p-2">
                                                {row.section}
                                            </td>
                                            <td className="p-2" dir="ltr">
                                                {row.number}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}
            </div>
        </>
    );
}
