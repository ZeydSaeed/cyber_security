import { useState } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/hooks/use-translations';

/** Minutes as whole days, hours and minutes. */
export function splitMinutes(total: number | null): {
    days: number | null;
    hours: number | null;
    minutes: number | null;
} {
    if (total === null || total <= 0) {
        return { days: null, hours: null, minutes: null };
    }

    return {
        days: Math.floor(total / 1440) || null,
        hours: Math.floor((total % 1440) / 60) || null,
        minutes: total % 60 || null,
    };
}

/** An ISO moment as the reader's local "YYYY-MM-DDTHH:mm" (datetime-local). */
function toLocalInput(iso: string | null): string {
    if (!iso) {
        return '';
    }
    const date = new Date(iso);
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/**
 * A length of time as days, hours and minutes (ADR-0043). The server adds
 * `<prefix>_days`, `<prefix>_hours` and `<prefix>_minutes` together; all
 * empty means none. Disabled fields are not sent. `form` ties the fields
 * to a form elsewhere on the page (the HTML form attribute).
 */
export function DurationFields({
    id,
    prefix,
    legend,
    value,
    disabled = false,
    hint,
    error,
    form,
}: {
    id: string;
    prefix: string;
    form?: string;
    legend: string;
    /** The saved length, in minutes. */
    value: number | null;
    disabled?: boolean;
    hint?: string;
    error?: string;
}) {
    const { t } = useTranslations();
    const parts = splitMinutes(value);
    const fields = [
        { key: 'days', label: t('Days'), max: 365, value: parts.days },
        { key: 'hours', label: t('Hours'), max: 8760, value: parts.hours },
        {
            key: 'minutes',
            label: t('Minutes'),
            max: 525600,
            value: parts.minutes,
        },
    ];

    return (
        <fieldset
            className="grid gap-1"
            disabled={disabled}
            aria-describedby={hint ? `${id}-hint` : undefined}
            data-test={`${prefix}-duration`}
        >
            <legend className="mb-1 text-sm font-medium">{legend}</legend>
            <div className="grid grid-cols-3 gap-2">
                {fields.map((field) => (
                    <div key={field.key} className="grid gap-1">
                        <Label
                            htmlFor={`${id}-${field.key}`}
                            className="text-xs text-muted-foreground"
                        >
                            {field.label}
                        </Label>
                        <Input
                            id={`${id}-${field.key}`}
                            name={`${prefix}_${field.key}`}
                            form={form}
                            type="number"
                            inputMode="numeric"
                            min={0}
                            max={field.max}
                            defaultValue={field.value ?? ''}
                            dir="ltr"
                            className="bg-background"
                        />
                    </div>
                ))}
            </div>
            {hint && (
                <p id={`${id}-hint`} className="text-xs text-muted-foreground">
                    {hint}
                </p>
            )}
            <InputError message={error} />
        </fieldset>
    );
}

/**
 * A date and time in the reader's local time, sent as an ISO moment with
 * its offset so the server (UTC) reads the same instant (ADR-0043).
 */
export function MomentField({
    id,
    name,
    label,
    value,
    disabled = false,
    hint,
    error,
    form,
}: {
    id: string;
    name: string;
    form?: string;
    label: string;
    value: string | null;
    disabled?: boolean;
    hint?: string;
    error?: string;
}) {
    const [local, setLocal] = useState(() => toLocalInput(value));
    const iso = local ? new Date(local).toISOString() : '';

    return (
        <div className="grid gap-1">
            <Label htmlFor={id} className="text-sm">
                {label}
            </Label>
            <Input
                id={id}
                type="datetime-local"
                value={local}
                onChange={(event) => setLocal(event.target.value)}
                disabled={disabled}
                dir="ltr"
                className="bg-background"
                aria-describedby={hint ? `${id}-hint` : undefined}
                data-test={`${name}-input`}
            />
            <input
                type="hidden"
                name={name}
                value={iso}
                disabled={disabled}
                form={form}
            />
            {hint && (
                <p id={`${id}-hint`} className="text-xs text-muted-foreground">
                    {hint}
                </p>
            )}
            <InputError message={error} />
        </div>
    );
}
