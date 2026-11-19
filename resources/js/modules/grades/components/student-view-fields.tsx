import { Form, usePage } from '@inertiajs/react';
import { Eye } from 'lucide-react';
import ResultSettingsController from '@/actions/App/Modules/Grades/Http/ResultSettingsController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/hooks/use-translations';
import type { ResultOptions } from '../types';
import { DurationFields, MomentField } from './duration-fields';
import type { ResultSettings } from './result-settings-panel';

/** The form the fields below belong to, wherever they are on the page. */
export const STUDENT_VIEW_FORM = 'student-view-form';

const selectClass =
    'h-9 w-full rounded-md border border-input bg-background px-3 text-base shadow-xs outline-none focus:border-ring focus:ring-[3px] focus:ring-ring/50 focus:outline-2 focus:outline-solid focus:outline-ring md:text-sm';

/**
 * The form of "What the student sees" (ADR-0044). It holds no fields: they
 * sit in the "Students" box of the access settings, which is itself a
 * form, and join this one through the HTML `form` attribute. Render it
 * outside any other form.
 */
export function StudentViewForm() {
    return (
        <Form
            {...ResultSettingsController.studentView.form()}
            id={STUDENT_VIEW_FORM}
            options={{ preserveScroll: true }}
            className="hidden"
        >
            {null}
        </Form>
    );
}

/**
 * What the student sees in the result, and the appeals (ADR-0043,
 * ADR-0044): the parts of the card, the appeal box, how long appeals stay
 * open, who may appeal, and whether teachers answer them.
 */
export function StudentViewFields({ settings }: { settings: ResultSettings }) {
    const { t } = useTranslations();
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    // In the order the owner listed them (2026-11-19), then the rest.
    const optionLabels: Record<keyof ResultOptions, string> = {
        absences: t("The student's absences"),
        percentage: t('Average (percentage)'),
        total: t('Total'),
        rank: t('Rank in the section'),
        mark_grade: t('Grade of each mark (excellent, good…)'),
        overall_grade: t('Overall grade'),
        behaviour: t('Behaviour'),
        notes: t('Notes'),
        appeals: t('Appeal box'),
        failed_count: t('Number of failed subjects'),
        exemption: t('Exemption'),
        max_mark: t('Each subject\'s maximum ("out of")'),
        teachers: t('Teachers of each subject'),
        kind: t('Theoretical or practical'),
        student_status: t('Student status (regular or external)'),
    };

    return (
        <div className="grid gap-4 border-t pt-3" data-test="student-view">
            <h3 className="flex items-center gap-2 text-sm font-semibold">
                <Eye className="size-4" aria-hidden />
                {t('What the student sees in the result')}
            </h3>
            <div className="grid gap-2 sm:grid-cols-2">
                {(Object.keys(optionLabels) as (keyof ResultOptions)[]).map(
                    (option) => (
                        <div key={option} className="flex items-center gap-2">
                            <Checkbox
                                id={`option-${option}`}
                                name="options[]"
                                value={option}
                                form={STUDENT_VIEW_FORM}
                                defaultChecked={settings.options[option]}
                                data-test={`option-${option}`}
                            />
                            <Label
                                htmlFor={`option-${option}`}
                                className="font-normal"
                            >
                                {optionLabels[option]}
                            </Label>
                        </div>
                    ),
                )}
            </div>
            <p className="text-xs text-muted-foreground">
                {t(
                    "Students see only what is ticked; the owner's results page shows everything.",
                )}
            </p>

            <fieldset className="grid gap-3" data-test="appeal-settings">
                <legend className="mb-1 text-sm font-semibold">
                    {t('Appeals')}
                </legend>
                <DurationFields
                    id="appeal-time"
                    prefix="appeal"
                    form={STUDENT_VIEW_FORM}
                    legend={t('Appeals stay open after publication for')}
                    value={settings.appeal_minutes}
                    hint={t(
                        'Empty here and in the date: students cannot appeal.',
                    )}
                    error={errors.appeal_minutes}
                />
                <MomentField
                    id="appeal-until"
                    name="appeal_until"
                    form={STUDENT_VIEW_FORM}
                    label={t('Or until a date')}
                    value={settings.appeal_until}
                    hint={t('Appeals close at this moment at the latest.')}
                    error={errors.appeal_until}
                />
                <div className="grid gap-1">
                    <Label htmlFor="appeal-scope">{t('Who may appeal')}</Label>
                    <select
                        id="appeal-scope"
                        name="appeal_scope"
                        form={STUDENT_VIEW_FORM}
                        defaultValue={settings.appeal_scope}
                        className={selectClass}
                        data-test="appeal-scope"
                    >
                        <option value="all">{t('Every student')}</option>
                        <option value="selected">
                            {t('Only the students I choose')}
                        </option>
                    </select>
                    <p className="text-xs text-muted-foreground">
                        {t(
                            'Choose them on the Students page or on a student\'s result card ("Allow an appeal").',
                        )}
                    </p>
                    <InputError message={errors.appeal_scope} />
                </div>
                <div className="flex items-center gap-2">
                    {/* An unticked box sends nothing: false by default. */}
                    <Checkbox
                        id="teacher-appeal-replies"
                        name="teacher_appeal_replies"
                        value="1"
                        form={STUDENT_VIEW_FORM}
                        defaultChecked={settings.teacher_appeal_replies}
                        data-test="teacher-appeal-replies"
                    />
                    <Label
                        htmlFor="teacher-appeal-replies"
                        className="font-normal"
                    >
                        {t(
                            'Teachers may answer the appeals of their own subjects',
                        )}
                    </Label>
                </div>
                <p className="text-xs text-muted-foreground">
                    {t(
                        'The teacher sees each appeal of their section on its sheet; you are told of every answer and can still answer the rest.',
                    )}
                </p>
            </fieldset>

            <Button
                type="submit"
                form={STUDENT_VIEW_FORM}
                variant="outline"
                className="self-start"
                data-test="save-student-view"
            >
                {t('Save what the student sees')}
            </Button>
        </div>
    );
}
