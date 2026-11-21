import { Form } from '@inertiajs/react';
import { Eye } from 'lucide-react';
import ResultSettingsController from '@/actions/App/Modules/Grades/Http/ResultSettingsController';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/hooks/use-translations';
import type { ResultOptions } from '../types';
import type { ResultSettings } from './result-settings-panel';

/** The form the fields below belong to, wherever they are on the page. */
export const STUDENT_VIEW_FORM = 'student-view-form';

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
 * What the student sees in the result (ADR-0044): the parts of the card.
 * The appeals have their own tab since 2026-11-20 (AppealsAdminPanel).
 */
export function StudentViewFields({ settings }: { settings: ResultSettings }) {
    const { t } = useTranslations();

    // In the order the owner listed them (2026-11-19), then the rest.
    // The appeal box is set on the appeals tab (2026-11-20).
    const optionLabels: Record<
        Exclude<keyof ResultOptions, 'appeals'>,
        string
    > = {
        absences: t("The student's absences"),
        percentage: t('Average (percentage)'),
        total: t('Total'),
        rank: t('Rank in the section'),
        mark_grade: t('Grade of each mark (excellent, good…)'),
        overall_grade: t('Overall grade'),
        behaviour: t('Behaviour'),
        notes: t('Notes'),
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
                {(
                    Object.keys(optionLabels) as (keyof typeof optionLabels)[]
                ).map((option) => (
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
                ))}
            </div>
            <p className="text-xs text-muted-foreground">
                {t(
                    "Students see only what is ticked; the owner's results page shows everything.",
                )}
            </p>

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
