import { Link, useForm } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import type { ReactNode } from 'react';
import { useRef } from 'react';
import EmptyState from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';
import { placeLabel } from '../place';
import type { MarkValue, SheetStudent } from '../types';

const ABSENT = 'absent';

/** What the field holds for a stored mark: digits, "absent", or empty. */
function initial(mark: MarkValue): string {
    return mark === null ? '' : String(mark);
}

/** A whole number from 0 to 100, as the server will check it. */
function isMark(value: string): boolean {
    return /^\d{1,3}$/.test(value.trim()) && Number(value) <= 100;
}

/**
 * One section's marks in one subject (ADR-0034), for a teacher and for the
 * owner's corrections alike: a number field and an "absent" box per
 * student, in name order. Enter moves to the next student, so a column of
 * marks is typed without the mouse. An empty field means no mark. The
 * server checks every value again and names the student of each error.
 */
export default function MarkSheet({
    subject,
    section,
    students,
    editable,
    saveUrl,
    back,
    notice,
}: {
    subject: { uuid: string; name: string; stage: number; divided: boolean };
    section: string;
    students: SheetStudent[];
    editable: boolean;
    saveUrl: string;
    back: { href: string; label: string };
    notice?: ReactNode;
}) {
    const { t } = useTranslations();
    const inputs = useRef<(HTMLInputElement | null)[]>([]);
    const form = useForm<{ marks: Record<string, string> }>({
        marks: Object.fromEntries(
            students.map((student) => [student.uuid, initial(student.mark)]),
        ),
    });
    const values = form.data.marks;
    const entered = Object.values(values).filter((v) => v.trim() !== '').length;

    const set = (uuid: string, value: string) =>
        form.setData('marks', { ...values, [uuid]: value });

    const save = (event: React.FormEvent) => {
        event.preventDefault();
        form.put(saveUrl, {
            preserveScroll: true,
            onSuccess: () => form.setDefaults(),
        });
    };

    return (
        <>
            <Link
                href={back.href}
                className="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
            >
                {/* Mirrored in Arabic, as elsewhere (table-pagination.tsx). */}
                <ChevronLeft className="size-4 rtl:-scale-x-100" aria-hidden />
                {back.label}
            </Link>

            <header className="mb-6">
                <h1 className="text-xl font-semibold">
                    <bdi>{subject.name}</bdi>
                </h1>
                <p className="text-sm text-muted-foreground">
                    {placeLabel(t, subject.stage, section, subject.divided)} ·{' '}
                    <span data-test="entered-count">
                        {t(':entered of :students entered', {
                            entered: String(entered),
                            students: String(students.length),
                        })}
                    </span>
                </p>
            </header>

            {notice}

            {form.errors.marks && (
                <p
                    role="alert"
                    className="mb-4 rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive-text"
                >
                    {form.errors.marks}
                </p>
            )}

            {students.length === 0 ? (
                <EmptyState
                    title={t('No students in this section')}
                    description={t('The department has not added them yet.')}
                />
            ) : (
                <form onSubmit={save} noValidate data-test="marks-form">
                    {/* A table with inner and outer borders (owner's
                        request, 2026-11-15): number, student, mark, absent. */}
                    <div className="overflow-x-auto rounded-lg border-2 border-grid-line bg-card">
                        <table className="w-full border-collapse text-sm">
                            <caption className="sr-only">
                                {subject.name}
                            </caption>
                            <thead>
                                <tr>
                                    <th
                                        scope="col"
                                        className="w-10 border border-grid-line p-2 text-center"
                                    >
                                        #
                                    </th>
                                    <th
                                        scope="col"
                                        className="border border-grid-line p-2 text-start"
                                    >
                                        {t('Name')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="w-24 border border-grid-line p-2 text-center"
                                    >
                                        {t('Mark out of 100')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="w-24 border border-grid-line p-2 text-center"
                                    >
                                        {t('Absent')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {students.map((student, index) => {
                                    const value = values[student.uuid] ?? '';
                                    const absent = value === ABSENT;
                                    const error =
                                        form.errors[
                                            `marks.${student.uuid}` as keyof typeof form.errors
                                        ];
                                    const invalid =
                                        !absent &&
                                        value.trim() !== '' &&
                                        !isMark(value);
                                    const fieldId = `mark-${student.uuid}`;
                                    const absentId = `absent-${student.uuid}`;
                                    const errorId = `error-${student.uuid}`;
                                    const cell = 'border border-grid-line p-2';

                                    return (
                                        <tr
                                            key={student.uuid}
                                            data-test="student-row"
                                        >
                                            <td
                                                className={`${cell} text-center tabular-nums`}
                                            >
                                                {index + 1}
                                            </td>
                                            <td className={cell}>
                                                <label
                                                    htmlFor={fieldId}
                                                    className="font-semibold"
                                                >
                                                    <bdi>{student.name}</bdi>
                                                </label>
                                                {(invalid || error) && (
                                                    <p
                                                        id={errorId}
                                                        className="mt-1 text-sm text-destructive-text"
                                                    >
                                                        {error ??
                                                            t(
                                                                'Enter a whole number from 0 to 100, or mark the student absent.',
                                                            )}
                                                    </p>
                                                )}
                                            </td>
                                            <td
                                                className={`${cell} text-center`}
                                            >
                                                <Input
                                                    ref={(element) => {
                                                        inputs.current[index] =
                                                            element;
                                                    }}
                                                    id={fieldId}
                                                    value={absent ? '' : value}
                                                    onChange={(event) =>
                                                        set(
                                                            student.uuid,
                                                            event.target.value,
                                                        )
                                                    }
                                                    onKeyDown={(event) => {
                                                        if (
                                                            event.key ===
                                                            'Enter'
                                                        ) {
                                                            event.preventDefault();
                                                            inputs.current[
                                                                index + 1
                                                            ]?.focus();
                                                        }
                                                    }}
                                                    disabled={
                                                        !editable || absent
                                                    }
                                                    inputMode="numeric"
                                                    maxLength={3}
                                                    dir="ltr"
                                                    enterKeyHint="next"
                                                    autoComplete="off"
                                                    placeholder={
                                                        absent
                                                            ? t('Absent')
                                                            : ''
                                                    }
                                                    aria-invalid={
                                                        invalid || error
                                                            ? true
                                                            : undefined
                                                    }
                                                    aria-describedby={
                                                        invalid || error
                                                            ? errorId
                                                            : undefined
                                                    }
                                                    className="mx-auto w-20 bg-background text-center font-semibold tabular-nums"
                                                    data-test="mark-input"
                                                />
                                            </td>
                                            <td
                                                className={`${cell} text-center`}
                                            >
                                                <Checkbox
                                                    id={absentId}
                                                    checked={absent}
                                                    disabled={!editable}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        set(
                                                            student.uuid,
                                                            checked === true
                                                                ? ABSENT
                                                                : '',
                                                        )
                                                    }
                                                    aria-label={t(
                                                        'Absent: :name',
                                                        { name: student.name },
                                                    )}
                                                    className="bg-background"
                                                    data-test="absent-box"
                                                />
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    {editable && (
                        <div className="sticky bottom-0 mt-4 flex items-center gap-3 border-t bg-background/95 py-3 backdrop-blur">
                            <Button
                                type="submit"
                                disabled={form.processing}
                                data-test="save-marks"
                            >
                                {form.processing && <Spinner />}
                                {t('Save marks')}
                            </Button>
                            {form.isDirty && (
                                <span
                                    className="text-sm text-muted-foreground"
                                    data-test="unsaved"
                                >
                                    {t('Unsaved changes')}
                                </span>
                            )}
                        </div>
                    )}
                </form>
            )}
        </>
    );
}
