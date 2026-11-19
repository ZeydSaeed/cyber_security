import { Form } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import ResultSettingsController from '@/actions/App/Modules/Grades/Http/ResultSettingsController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/hooks/use-translations';
import { bandLabel, toneClass } from '../result-words';
import type { Band, MarkTones, ResultOptions, Tone } from '../types';

export type ResultSettings = {
    pass_percent: number;
    exempt_subject_percent: number | null;
    exempt_overall_percent: number | null;
    bands: Record<Exclude<Band, 'fail'>, number>;
    options: ResultOptions;
    tones: MarkTones;
    /** When teachers may read their students' results (ADR-0040). */
    teacher_results: 'off' | 'published' | 'always';
    teacher_view: Record<string, boolean>;
    /** Days to appeal after publication; null: no appeals (ADR-0042). */
    appeal_days: number | null;
    /** Flag a student absent from this many subjects; null: no flag. */
    absence_flag: number | null;
};

const selectClass =
    'h-9 w-full rounded-md border border-input bg-background px-3 text-base shadow-xs outline-none focus:border-ring focus:ring-[3px] focus:ring-ring/50 focus:outline-2 focus:outline-solid focus:outline-ring md:text-sm';

const BANDS = ['excellent', 'very_good', 'good', 'fair', 'pass'] as const;
const TONES: Tone[] = ['none', 'success', 'warning', 'info', 'danger'];

function Percent({
    id,
    name,
    label,
    value,
    hint,
    error,
    required = false,
}: {
    id: string;
    name: string;
    label: string;
    value: number | null;
    hint?: string;
    error?: string;
    required?: boolean;
}) {
    return (
        <div className="grid gap-1">
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                name={name}
                type="number"
                inputMode="numeric"
                min={required ? 1 : 0}
                max={100}
                defaultValue={value ?? ''}
                required={required}
                dir="ltr"
                className="bg-background"
                aria-describedby={hint ? `${id}-hint` : undefined}
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

/**
 * What a result says and shows (ADR-0039), on the owner's overview: the
 * pass and exemption percentages, the grade bands, the parts of the result
 * card (what students see and what is printed), and the tones of marks,
 * each a status tone of the design system, never a colour value.
 */
export default function ResultSettingsPanel({
    settings,
}: {
    settings: ResultSettings;
}) {
    const { t } = useTranslations();
    const optionLabels: Record<keyof ResultOptions, string> = {
        total: t('Total'),
        percentage: t('Average (percentage)'),
        rank: t('Rank in the section'),
        failed_count: t('Number of failed subjects'),
        mark_grade: t('Grade of each mark'),
        overall_grade: t('Overall grade'),
        exemption: t('Exemption'),
        max_mark: t('Each subject\'s maximum ("out of")'),
        teachers: t('Teachers of each subject'),
        kind: t('Theoretical or practical'),
        student_status: t('Student status (regular or external)'),
        behaviour: t('Behaviour'),
        notes: t('Notes'),
    };
    const toneLabels: Record<keyof MarkTones, string> = {
        fail: t('Failed or absent'),
        fair: t('Fair'),
        exempt: t('Exempt'),
        excellent: t('Excellent'),
    };
    const teacherLabels: Record<string, string> = {
        other_subjects: t('Marks of the other subjects'),
        total: t('Total'),
        percentage: t('Average (percentage)'),
        rank: t('Rank in the section'),
        failed_count: t('Number of failed subjects'),
        mark_grade: t('Grade of each mark'),
        overall_grade: t('Overall grade'),
        exemption: t('Exemption'),
        student_status: t('Student status (regular or external)'),
        behaviour: t('Behaviour'),
        notes: t('Notes'),
    };
    const toneNames: Record<Tone, string> = {
        none: t('No colour'),
        success: t('Green (success)'),
        warning: t('Amber (warning)'),
        info: t('Blue (information)'),
        danger: t('Red (danger)'),
    };

    return (
        <section
            className="grid gap-4 rounded-lg border bg-card p-4"
            aria-labelledby="result-settings-title"
            data-test="result-settings"
        >
            <header className="flex items-center gap-3">
                <FileText className="size-5" aria-hidden />
                <h2 id="result-settings-title" className="font-semibold">
                    {t('The result card')}
                </h2>
            </header>

            <Form
                {...ResultSettingsController.settings.form()}
                options={{ preserveScroll: true }}
                className="grid gap-6"
            >
                {({ errors, processing }) => (
                    <>
                        <fieldset className="grid gap-3 sm:grid-cols-3">
                            <legend className="mb-2 text-sm font-semibold">
                                {t('Passing and exemption')}
                            </legend>
                            <Percent
                                id="pass-percent"
                                name="pass_percent"
                                label={t('Pass percentage')}
                                value={settings.pass_percent}
                                required
                                hint={t(
                                    "Of each subject's maximum, unless the subject has its own pass mark.",
                                )}
                                error={errors.pass_percent}
                            />
                            <Percent
                                id="exempt-subject"
                                name="exempt_subject_percent"
                                label={t('Individual exemption (%)')}
                                value={settings.exempt_subject_percent}
                                hint={t(
                                    'A subject at or above it is exempt. Empty: none.',
                                )}
                                error={errors.exempt_subject_percent}
                            />
                            <Percent
                                id="exempt-overall"
                                name="exempt_overall_percent"
                                label={t('General exemption (%)')}
                                value={settings.exempt_overall_percent}
                                hint={t(
                                    'A student who passed everything at or above this average is exempt from every subject. Empty: none.',
                                )}
                                error={errors.exempt_overall_percent}
                            />
                        </fieldset>

                        <fieldset className="grid gap-3 sm:grid-cols-2">
                            <legend className="mb-2 text-sm font-semibold">
                                {t('Appeals and absences')}
                            </legend>
                            <div className="grid gap-1">
                                <Label htmlFor="appeal-days">
                                    {t('Days to appeal after publication')}
                                </Label>
                                <Input
                                    id="appeal-days"
                                    name="appeal_days"
                                    type="number"
                                    inputMode="numeric"
                                    min={1}
                                    max={90}
                                    defaultValue={settings.appeal_days ?? ''}
                                    dir="ltr"
                                    className="bg-background"
                                />
                                <p className="text-xs text-muted-foreground">
                                    {t('Empty: students cannot appeal.')}
                                </p>
                                <InputError message={errors.appeal_days} />
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="absence-flag">
                                    {t('Flag a student absent from (subjects)')}
                                </Label>
                                <Input
                                    id="absence-flag"
                                    name="absence_flag"
                                    type="number"
                                    inputMode="numeric"
                                    min={1}
                                    max={50}
                                    defaultValue={settings.absence_flag ?? ''}
                                    dir="ltr"
                                    className="bg-background"
                                />
                                <p className="text-xs text-muted-foreground">
                                    {t(
                                        'Shown to the department and the teachers. Empty: no flag.',
                                    )}
                                </p>
                                <InputError message={errors.absence_flag} />
                            </div>
                        </fieldset>

                        <fieldset className="grid gap-3">
                            <legend className="mb-2 text-sm font-semibold">
                                {t('Grades: lowest percentage of each')}
                            </legend>
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                                {BANDS.map((band) => (
                                    <div key={band} className="grid gap-1">
                                        <Label htmlFor={`band-${band}`}>
                                            {bandLabel(t, band)}
                                        </Label>
                                        <Input
                                            id={`band-${band}`}
                                            name={`bands[${band}]`}
                                            type="number"
                                            inputMode="numeric"
                                            min={0}
                                            max={100}
                                            required
                                            defaultValue={settings.bands[band]}
                                            dir="ltr"
                                            className="bg-background"
                                        />
                                    </div>
                                ))}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {t(
                                    'Below the pass mark is always "Fail", whatever the percentage.',
                                )}
                            </p>
                            <InputError message={errors.bands} />
                        </fieldset>

                        <fieldset className="grid gap-2">
                            <legend className="mb-2 text-sm font-semibold">
                                {t('Shown on the result card')}
                            </legend>
                            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                {(
                                    Object.keys(
                                        optionLabels,
                                    ) as (keyof ResultOptions)[]
                                ).map((option) => (
                                    <div
                                        key={option}
                                        className="flex items-center gap-2"
                                    >
                                        <Checkbox
                                            id={`option-${option}`}
                                            name="options[]"
                                            value={option}
                                            defaultChecked={
                                                settings.options[option]
                                            }
                                            data-test={`option-${option}`}
                                        />
                                        <Label
                                            htmlFor={`option-${option}`}
                                            className="font-normal"
                                        >
                                            {optionLabels[option]}
                                        </Label>
                                    </div>
                                ))}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {t(
                                    "Students see only what is ticked; the owner's results page shows everything.",
                                )}
                            </p>
                        </fieldset>

                        <fieldset className="grid gap-3">
                            <legend className="mb-2 text-sm font-semibold">
                                {t('Colours of marks')}
                            </legend>
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                {(
                                    Object.keys(
                                        toneLabels,
                                    ) as (keyof MarkTones)[]
                                ).map((key) => (
                                    <div key={key} className="grid gap-1">
                                        <Label htmlFor={`tone-${key}`}>
                                            {toneLabels[key]}
                                        </Label>
                                        <select
                                            id={`tone-${key}`}
                                            name={`tones[${key}]`}
                                            defaultValue={settings.tones[key]}
                                            className={selectClass}
                                        >
                                            {TONES.map((tone) => (
                                                <option key={tone} value={tone}>
                                                    {toneNames[tone]}
                                                </option>
                                            ))}
                                        </select>
                                        <span
                                            className={`text-sm ${toneClass(settings.tones[key])}`}
                                        >
                                            {t('Sample: 73')}
                                        </span>
                                    </div>
                                ))}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {t(
                                    'Colours come from the design system and always go with words.',
                                )}
                            </p>
                        </fieldset>

                        <Button
                            className="self-start"
                            disabled={processing}
                            data-test="save-result-settings"
                        >
                            {t('Save the result settings')}
                        </Button>
                    </>
                )}
            </Form>

            <Form
                {...ResultSettingsController.teacherView.form()}
                options={{ preserveScroll: true }}
                className="grid gap-4 border-t pt-4"
                data-test="teacher-view-form"
            >
                {({ errors, processing }) => (
                    <>
                        <fieldset className="grid gap-3">
                            <legend className="mb-2 text-sm font-semibold">
                                {t(
                                    "What teachers see of their students' results",
                                )}
                            </legend>
                            <div className="grid max-w-sm gap-1">
                                <Label htmlFor="teacher-results">
                                    {t('Teachers may read the results')}
                                </Label>
                                <select
                                    id="teacher-results"
                                    name="teacher_results"
                                    defaultValue={settings.teacher_results}
                                    className={selectClass}
                                    data-test="teacher-results"
                                >
                                    <option value="off">{t('Never')}</option>
                                    <option value="published">
                                        {t('Once published to the students')}
                                    </option>
                                    <option value="always">
                                        {t('Always, while marks come in too')}
                                    </option>
                                </select>
                                <InputError message={errors.teacher_results} />
                            </div>
                            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                {(Object.keys(teacherLabels) as string[]).map(
                                    (option) => (
                                        <div
                                            key={option}
                                            className="flex items-center gap-2"
                                        >
                                            <Checkbox
                                                id={`teacher-view-${option}`}
                                                name="teacher_view[]"
                                                value={option}
                                                defaultChecked={
                                                    settings.teacher_view[
                                                        option
                                                    ]
                                                }
                                                data-test={`teacher-view-${option}`}
                                            />
                                            <Label
                                                htmlFor={`teacher-view-${option}`}
                                                className="font-normal"
                                            >
                                                {teacherLabels[option]}
                                            </Label>
                                        </div>
                                    ),
                                )}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {t(
                                    'A teacher sees only the sections they teach, and always the marks of their own subjects.',
                                )}
                            </p>
                        </fieldset>
                        <Button
                            className="self-start"
                            variant="outline"
                            disabled={processing}
                            data-test="save-teacher-view"
                        >
                            {t('Save what teachers see')}
                        </Button>
                    </>
                )}
            </Form>
        </section>
    );
}
