import { Form } from '@inertiajs/react';
import {
    Building2,
    CircleCheck,
    CircleX,
    GraduationCap,
    Search,
} from 'lucide-react';
import ResultSettingsController from '@/actions/App/Modules/Grades/Http/ResultSettingsController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';
import { AppealRow } from './access-panel';
import type { AccessBoardProps } from './access-panel';
import { DurationFields, MomentField } from './duration-fields';
import type { ResultSettings } from './result-settings-panel';
import { StatusSummary } from './status-tiles';

export type AppealCounts = {
    pending: number;
    accepted: number;
    rejected: number;
    by_teacher: number;
    by_department: number;
};

const selectClass =
    'h-9 w-full rounded-md border border-input bg-background px-3 text-base shadow-xs outline-none focus:border-ring focus:ring-[3px] focus:ring-ring/50 focus:outline-2 focus:outline-solid focus:outline-ring md:text-sm';

/**
 * Students' appeals, a tab of their own (owner's request, 2026-11-20):
 * the appeals at a glance in the picture's layout, their settings (on or
 * off, who answers, how long and between which dates, who may appeal),
 * and those waiting for the department's answer.
 */
export default function AppealsAdminPanel({
    settings,
    counts,
    appeals,
}: {
    settings: ResultSettings;
    counts: AppealCounts;
    appeals: AccessBoardProps['appeals'];
}) {
    const { t } = useTranslations();

    return (
        <div className="mx-auto flex w-full max-w-6xl flex-col gap-6">
            <StatusSummary
                title={t("Students' appeals")}
                testId="appeal-summary"
                items={[
                    {
                        key: 'pending',
                        label: t('Waiting for an answer'),
                        count: counts.pending,
                        tile: 4,
                        icon: Search,
                        inBar: true,
                    },
                    {
                        key: 'accepted',
                        label: t('Accepted'),
                        count: counts.accepted,
                        tile: 1,
                        icon: CircleCheck,
                        inBar: true,
                    },
                    {
                        key: 'rejected',
                        label: t('Rejected'),
                        count: counts.rejected,
                        tile: 5,
                        icon: CircleX,
                        inBar: true,
                    },
                    {
                        key: 'by-teacher',
                        label: t('Answered by the subject teacher'),
                        count: counts.by_teacher,
                        tile: 2,
                        icon: GraduationCap,
                    },
                    {
                        key: 'by-department',
                        label: t('Answered by the administration'),
                        count: counts.by_department,
                        tile: 3,
                        icon: Building2,
                    },
                ]}
            />

            <Form
                {...ResultSettingsController.appeals.form()}
                options={{ preserveScroll: true }}
                className="grid gap-5 rounded-lg border bg-card p-4"
                data-test="appeal-settings"
            >
                {({ errors, processing }) => (
                    <>
                        <h2 className="font-semibold">
                            {t('Appeal settings')}
                        </h2>

                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="appeals-enabled"
                                name="enabled"
                                value="1"
                                defaultChecked={settings.options.appeals}
                                data-test="appeals-enabled"
                            />
                            <Label
                                htmlFor="appeals-enabled"
                                className="font-normal"
                            >
                                {t(
                                    'Students may appeal their marks (the appeal box on the result)',
                                )}
                            </Label>
                        </div>

                        <fieldset className="grid gap-2">
                            <legend className="mb-1 text-sm font-semibold">
                                {t('Who answers the appeals')}
                            </legend>
                            <div className="grid gap-2 md:grid-cols-2">
                                {(
                                    [
                                        {
                                            value: 'teacher',
                                            label: t('The subject teacher'),
                                            hint: t(
                                                'Each appeal goes to the teacher of the appealed subject, who answers it on their sheet. You are told of every answer and can still answer.',
                                            ),
                                        },
                                        {
                                            value: 'department',
                                            label: t('The administration'),
                                            hint: t(
                                                'Only the administration answers, here. The teacher sees the appeals of their sections but cannot answer them.',
                                            ),
                                        },
                                    ] as const
                                ).map((choice) => (
                                    <label
                                        key={choice.value}
                                        className={cn(
                                            'flex cursor-pointer items-start gap-3 rounded-md border bg-background p-3 has-[:checked]:border-ring has-[:checked]:ring-2 has-[:checked]:ring-ring/30',
                                        )}
                                    >
                                        <input
                                            type="radio"
                                            name="answered_by"
                                            value={choice.value}
                                            defaultChecked={
                                                settings.teacher_appeal_replies ===
                                                (choice.value === 'teacher')
                                            }
                                            className="mt-1 size-4 accent-primary"
                                            data-test={`answered-by-${choice.value}`}
                                        />
                                        <span className="grid gap-1">
                                            <span className="text-sm font-medium">
                                                {choice.label}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {choice.hint}
                                            </span>
                                        </span>
                                    </label>
                                ))}
                            </div>
                            <InputError message={errors.answered_by} />
                        </fieldset>

                        <fieldset className="grid gap-4">
                            <legend className="mb-1 text-sm font-semibold">
                                {t('When students may appeal')}
                            </legend>
                            <div className="grid gap-4 md:grid-cols-2">
                                <MomentField
                                    id="appeal-from"
                                    name="appeal_from"
                                    label={t('Open from a date')}
                                    value={settings.appeal_from}
                                    hint={t(
                                        'Empty: appeals open as soon as the results are published.',
                                    )}
                                    error={errors.appeal_from}
                                />
                                <MomentField
                                    id="appeal-until"
                                    name="appeal_until"
                                    label={t('Close at a date')}
                                    value={settings.appeal_until}
                                    hint={t(
                                        'Appeals close at this moment at the latest.',
                                    )}
                                    error={errors.appeal_until}
                                />
                            </div>
                            <DurationFields
                                id="appeal-time"
                                prefix="appeal"
                                legend={t(
                                    'Or keep appeals open, once they open, for',
                                )}
                                value={settings.appeal_minutes}
                                hint={t(
                                    'In days, hours or minutes. With a closing date too, the earlier one applies. While appeals are allowed, give this time or a closing date.',
                                )}
                                error={errors.appeal_minutes}
                            />
                        </fieldset>

                        <div className="grid max-w-md gap-1">
                            <Label htmlFor="appeal-scope">
                                {t('Who may appeal')}
                            </Label>
                            <select
                                id="appeal-scope"
                                name="appeal_scope"
                                defaultValue={settings.appeal_scope}
                                className={selectClass}
                                data-test="appeal-scope"
                            >
                                <option value="all">
                                    {t('Every student')}
                                </option>
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

                        <Button
                            type="submit"
                            className="justify-self-start"
                            disabled={processing}
                            data-test="save-appeal-settings"
                        >
                            {t('Save the appeal settings')}
                        </Button>
                    </>
                )}
            </Form>

            <section
                className="grid gap-2 rounded-lg border bg-card p-4"
                aria-labelledby="waiting-appeals"
            >
                <h2
                    id="waiting-appeals"
                    className="flex items-center gap-2 font-semibold"
                >
                    {t('Appeals waiting for an answer')}
                    <Badge>{appeals.length}</Badge>
                </h2>
                {appeals.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('No appeal is waiting.')}
                    </p>
                ) : (
                    <ul className="grid gap-2">
                        {appeals.map((appeal) => (
                            <AppealRow key={appeal.uuid} appeal={appeal} />
                        ))}
                    </ul>
                )}
            </section>
        </div>
    );
}
