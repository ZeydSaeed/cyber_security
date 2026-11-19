import { Form, router } from '@inertiajs/react';
import { Check, KeyRound, ShieldCheck, X } from 'lucide-react';
import { useState } from 'react';
import AccessController from '@/actions/App/Modules/Grades/Http/AccessController';
import AppealsController from '@/actions/App/Modules/Grades/Http/AppealsController';
import ConfirmAction from '@/components/confirm-action';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/hooks/use-translations';
import { placeLabel } from '../place';
import type { TeacherMode } from '../types';
import { useMoment } from './access-notice';
import { DurationFields, MomentField } from './duration-fields';
import type { ResultSettings } from './result-settings-panel';
import { StudentViewFields, StudentViewForm } from './student-view-fields';

/** The modes a time limit applies to (ADR-0043). */
const usesLimit = (mode: TeacherMode) => mode === 'edit' || mode === 'timed';

/**
 * How long a mark stays changeable, and the date every mark locks
 * (ADR-0043). Only "Enter and change" and "Change for a limited time" use
 * them; otherwise the fields are disabled and the saved values kept.
 */
function EditLimitFields({
    id,
    prefix,
    minutes,
    until,
    enabled,
    errors,
    inherited = false,
}: {
    id: string;
    prefix: 'teacher_edit' | 'edit';
    minutes: number | null;
    until: string | null;
    enabled: boolean;
    errors: Record<string, string | undefined>;
    /** A teacher's fields: empty means the department's. */
    inherited?: boolean;
}) {
    const { t } = useTranslations();
    const hint = !enabled
        ? t(
              'Used only with "Enter and change" and "Change for a limited time".',
          )
        : inherited
          ? t(
                "Counted from when each mark is first entered. Empty here and in the date: the department's.",
            )
          : t('Counted from when each mark is first entered. Empty: no limit.');

    return (
        <div className="grid gap-3" data-test={`${prefix}-limit`}>
            <DurationFields
                id={`${id}-time`}
                prefix={prefix}
                legend={t('Time a mark stays changeable')}
                value={minutes}
                disabled={!enabled}
                hint={hint}
                error={errors[`${prefix}_minutes`]}
            />
            <MomentField
                id={`${id}-until`}
                name={`${prefix}_until`}
                label={t('Or until a date')}
                value={until}
                disabled={!enabled}
                hint={
                    enabled
                        ? t(
                              'Every mark locks at this moment, even if its time is not over. Empty: no date.',
                          )
                        : undefined
                }
                error={errors[`${prefix}_until`]}
            />
        </div>
    );
}

export type AccessSettings = {
    teacher_attempts_per_minute: number;
    teacher_attempts_per_hour: number;
    student_attempts_per_minute: number;
    student_attempts_per_hour: number;
    teacher_max_logins: number | null;
    student_max_logins: number | null;
    teacher_mode: TeacherMode;
    /** Minutes a mark stays changeable (ADR-0043); null: no limit. */
    teacher_edit_minutes: number | null;
    /** When every mark locks; null: no date. */
    teacher_edit_until: string | null;
    student_access: 'allowed' | 'blocked';
    /** Days a device stays remembered (ADR-0038); 0: off. */
    remember_days: number;
};

type AccessSection = {
    subject: string;
    name: string;
    stage: number;
    divided: boolean;
    section: string;
    mode: TeacherMode | null;
    reason: string | null;
    edit_until: string | null;
};

type AccessTeacher = {
    uuid: string;
    name: string;
    active: boolean;
    mode: TeacherMode | null;
    effective_mode: TeacherMode;
    edit_minutes: number | null;
    edit_until: string | null;
    reason: string | null;
    logins: number;
    last_login_at: string | null;
    refused: number;
    /** Remembered devices not expired. */
    devices: number;
    sections: AccessSection[];
};

export type AccessBoardProps = {
    settings: AccessSettings;
    teachers: AccessTeacher[];
    /** Students' appeals waiting for an answer (ADR-0042). */
    appeals: {
        uuid: string;
        student: string | null;
        stage: number | null;
        section: string | null;
        divided: boolean;
        subject: string | null;
        subject_uuid: string | null;
        reason: string;
        created_at: string;
    }[];
    requests: {
        uuid: string;
        teacher: string | null;
        subject: string | null;
        stage: number | null;
        divided: boolean;
        section: string;
        reason: string;
        created_at: string;
    }[];
    students: {
        total: number;
        signed_in: number;
        logins: number;
        blocked: number;
    };
    failed: { teachers: number; students: number; lockouts: number };
};

const selectClass =
    'h-9 w-full rounded-md border border-input bg-background px-3 text-base shadow-xs outline-none focus:border-ring focus:ring-[3px] focus:ring-ring/50 focus:outline-2 focus:outline-solid focus:outline-ring md:text-sm';

function useModeLabel() {
    const { t } = useTranslations();

    return (mode: TeacherMode): string =>
        ({
            edit: t('Enter and change'),
            timed: t('Change for a limited time'),
            view_only: t('Read only'),
            blocked: t('No access'),
        })[mode];
}

/** The modes, after an "inherited" choice when the setting may be unset. */
function ModeOptions({ inherit }: { inherit?: string }) {
    const label = useModeLabel();

    return (
        <>
            {inherit !== undefined && <option value="">{inherit}</option>}
            {(['edit', 'timed', 'view_only', 'blocked'] as const).map(
                (mode) => (
                    <option key={mode} value={mode}>
                        {label(mode)}
                    </option>
                ),
            )}
        </>
    );
}

function NumberField({
    id,
    name,
    label,
    value,
    min = 1,
    max,
    error,
    hint,
}: {
    id: string;
    name: string;
    label: string;
    value: number | null;
    min?: number;
    max: number;
    error?: string;
    hint?: string;
}) {
    return (
        <div className="grid gap-1">
            <Label htmlFor={id} className="text-sm">
                {label}
            </Label>
            <Input
                id={id}
                name={name}
                type="number"
                inputMode="numeric"
                min={min}
                max={max}
                defaultValue={value ?? ''}
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

/** The department's rules for signing in and changing marks. */
function SettingsForm({
    settings,
    who,
}: {
    settings: AccessSettings;
    /** Each side has its own tab; the server keeps the other's values. */
    who: 'teachers' | 'students';
}) {
    const { t } = useTranslations();
    const [mode, setMode] = useState<TeacherMode>(settings.teacher_mode);

    return (
        <Form
            {...AccessController.settings.form()}
            options={{ preserveScroll: true }}
            className="grid max-w-3xl gap-4"
            data-test={`access-settings-${who}`}
        >
            {({ errors, processing }) => (
                <>
                    {who === 'teachers' && (
                        <fieldset className="grid content-start gap-3 rounded-md border p-3">
                            <legend className="px-1 text-sm font-semibold">
                                {t('Teachers')}
                            </legend>
                            <div className="grid grid-cols-2 gap-3">
                                <NumberField
                                    id="teacher-per-minute"
                                    name="teacher_attempts_per_minute"
                                    label={t('Wrong codes a minute')}
                                    value={settings.teacher_attempts_per_minute}
                                    max={100}
                                    error={errors.teacher_attempts_per_minute}
                                />
                                <NumberField
                                    id="teacher-per-hour"
                                    name="teacher_attempts_per_hour"
                                    label={t('Wrong codes an hour')}
                                    value={settings.teacher_attempts_per_hour}
                                    max={1000}
                                    error={errors.teacher_attempts_per_hour}
                                />
                            </div>
                            <NumberField
                                id="teacher-max-logins"
                                name="teacher_max_logins"
                                label={t('Most sign-ins per teacher')}
                                value={settings.teacher_max_logins}
                                max={100000}
                                hint={t('Leave empty for no limit.')}
                                error={errors.teacher_max_logins}
                            />
                            <div className="grid gap-1">
                                <Label htmlFor="teacher-mode">
                                    {t('Teachers may')}
                                </Label>
                                <select
                                    id="teacher-mode"
                                    name="teacher_mode"
                                    value={mode}
                                    onChange={(event) =>
                                        setMode(
                                            event.target.value as TeacherMode,
                                        )
                                    }
                                    className={selectClass}
                                    data-test="teacher-mode"
                                >
                                    <ModeOptions />
                                </select>
                                <InputError message={errors.teacher_mode} />
                            </div>
                            <EditLimitFields
                                id="teacher-edit"
                                prefix="teacher_edit"
                                minutes={settings.teacher_edit_minutes}
                                until={settings.teacher_edit_until}
                                enabled={usesLimit(mode)}
                                errors={errors}
                            />
                            {mode === 'blocked' && (
                                <p className="text-sm text-destructive-text">
                                    {t(
                                        'No teacher can sign in while this is chosen.',
                                    )}
                                </p>
                            )}
                        </fieldset>
                    )}

                    {who === 'students' && (
                        <fieldset className="grid content-start gap-3 rounded-md border p-3">
                            <legend className="px-1 text-sm font-semibold">
                                {t('Students')}
                            </legend>
                            <div className="grid grid-cols-2 gap-3">
                                <NumberField
                                    id="student-per-minute"
                                    name="student_attempts_per_minute"
                                    label={t('Wrong codes a minute')}
                                    value={settings.student_attempts_per_minute}
                                    max={100}
                                    error={errors.student_attempts_per_minute}
                                />
                                <NumberField
                                    id="student-per-hour"
                                    name="student_attempts_per_hour"
                                    label={t('Wrong codes an hour')}
                                    value={settings.student_attempts_per_hour}
                                    max={1000}
                                    error={errors.student_attempts_per_hour}
                                />
                            </div>
                            <NumberField
                                id="student-max-logins"
                                name="student_max_logins"
                                label={t('Most sign-ins per student')}
                                value={settings.student_max_logins}
                                max={100000}
                                hint={t('Leave empty for no limit.')}
                                error={errors.student_max_logins}
                            />
                            <div className="grid gap-1">
                                <Label htmlFor="student-access">
                                    {t('Students may sign in')}
                                </Label>
                                <select
                                    id="student-access"
                                    name="student_access"
                                    defaultValue={settings.student_access}
                                    className={selectClass}
                                    data-test="student-access"
                                >
                                    <option value="allowed">{t('Yes')}</option>
                                    <option value="blocked">
                                        {t('No, closed for now')}
                                    </option>
                                </select>
                                <InputError message={errors.student_access} />
                            </div>
                        </fieldset>
                    )}
                    {who === 'teachers' && (
                        <div className="max-w-sm">
                            <NumberField
                                id="remember-days"
                                name="remember_days"
                                label={t('Remember a device for (days)')}
                                value={settings.remember_days}
                                min={0}
                                max={365}
                                hint={t(
                                    'Teachers and students who tick "Remember this device" go straight in for this many days, until their code changes. 0 turns it off.',
                                )}
                                error={errors.remember_days}
                            />
                        </div>
                    )}
                    <Button
                        className="self-start"
                        disabled={processing}
                        data-test="save-access-settings"
                    >
                        {t('Save')}
                    </Button>
                </>
            )}
        </Form>
    );
}

/** One section's own access for its teacher, and a grant to edit. */
function SectionForm({ section }: { section: AccessSection }) {
    const { t } = useTranslations();
    const moment = useMoment();
    const id = `${section.subject}-${section.section}`;

    return (
        <Form
            {...AccessController.section.form({
                subject: section.subject,
                section: section.section,
            })}
            options={{ preserveScroll: true }}
            className="grid gap-2 rounded-md border p-3"
            data-test="section-access-form"
        >
            {({ errors, processing }) => (
                <>
                    <p className="text-sm font-medium">
                        <bdi>{section.name}</bdi>{' '}
                        <span className="text-muted-foreground">
                            {placeLabel(
                                t,
                                section.stage,
                                section.section,
                                section.divided,
                            )}
                        </span>
                    </p>
                    {section.edit_until && (
                        <p className="text-xs text-success-text">
                            {t('Allowed to change until :time.', {
                                time: moment(section.edit_until),
                            })}
                        </p>
                    )}
                    <div className="grid gap-2 sm:grid-cols-3">
                        <div className="grid gap-1">
                            <Label htmlFor={`${id}-mode`} className="text-xs">
                                {t('Access in this section')}
                            </Label>
                            <select
                                id={`${id}-mode`}
                                name="mode"
                                defaultValue={section.mode ?? ''}
                                className={selectClass}
                            >
                                <ModeOptions inherit={t('As the teacher')} />
                            </select>
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor={`${id}-grant`} className="text-xs">
                                {t('Allow changes for (hours)')}
                            </Label>
                            <Input
                                id={`${id}-grant`}
                                name="grant_hours"
                                type="number"
                                inputMode="numeric"
                                min={0}
                                max={8760}
                                dir="ltr"
                                placeholder={t('Unchanged')}
                                className="bg-background"
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor={`${id}-reason`} className="text-xs">
                                {t('Reason')}
                            </Label>
                            <Input
                                id={`${id}-reason`}
                                name="reason"
                                maxLength={255}
                                defaultValue={section.reason ?? ''}
                                dir="auto"
                                className="bg-background"
                            />
                        </div>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {t(
                            'Allowing changes opens the section even with mark entry closed; 0 withdraws it.',
                        )}
                    </p>
                    <InputError
                        message={
                            errors.mode ??
                            errors.grant_hours ??
                            errors.reason ??
                            errors.section
                        }
                    />
                    <Button
                        size="sm"
                        variant="outline"
                        className="self-start"
                        disabled={processing}
                    >
                        {t('Save')}
                    </Button>
                </>
            )}
        </Form>
    );
}

/** A teacher's own access, their sections, and their sign-in count. */
function TeacherAccessDialog({
    teacher,
    departmentMode,
    onClose,
}: {
    teacher: AccessTeacher;
    departmentMode: TeacherMode;
    onClose: () => void;
}) {
    const { t } = useTranslations();
    const [mode, setMode] = useState<TeacherMode | ''>(teacher.mode ?? '');

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto bg-card sm:max-w-2xl"
                data-test="teacher-access-dialog"
            >
                <DialogTitle>
                    {t('Access of :name', { name: teacher.name })}
                </DialogTitle>
                <DialogDescription>
                    {t(
                        "A section's own setting comes first, then the teacher's, then the department's.",
                    )}
                </DialogDescription>

                <Form
                    {...AccessController.teacher.form(teacher.uuid)}
                    options={{ preserveScroll: true }}
                    className="grid gap-3 rounded-md border p-3"
                    data-test="teacher-access-form"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="grid gap-1">
                                    <Label htmlFor="access-mode">
                                        {t('The teacher may')}
                                    </Label>
                                    <select
                                        id="access-mode"
                                        name="mode"
                                        value={mode}
                                        onChange={(event) =>
                                            setMode(
                                                event.target.value as
                                                    | TeacherMode
                                                    | '',
                                            )
                                        }
                                        className={selectClass}
                                        data-test="teacher-access-mode"
                                    >
                                        <ModeOptions
                                            inherit={t('As the department')}
                                        />
                                    </select>
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor="access-reason">
                                        {t('Reason')}
                                    </Label>
                                    <Input
                                        id="access-reason"
                                        name="reason"
                                        maxLength={255}
                                        defaultValue={teacher.reason ?? ''}
                                        dir="auto"
                                        className="bg-background"
                                    />
                                    <InputError message={errors.reason} />
                                </div>
                            </div>
                            <EditLimitFields
                                id="access-edit"
                                prefix="edit"
                                minutes={teacher.edit_minutes}
                                until={teacher.edit_until}
                                enabled={usesLimit(mode || departmentMode)}
                                errors={errors}
                                inherited
                            />
                            <InputError message={errors.mode} />
                            <Button
                                className="self-start"
                                disabled={processing}
                                data-test="save-teacher-access"
                            >
                                {t('Save')}
                            </Button>
                        </>
                    )}
                </Form>

                <section className="grid gap-2">
                    <h3 className="text-sm font-semibold">
                        {t('Sections they teach')}
                    </h3>
                    {teacher.sections.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('No subjects yet.')}
                        </p>
                    ) : (
                        teacher.sections.map((section) => (
                            <SectionForm
                                key={`${section.subject}-${section.section}`}
                                section={section}
                            />
                        ))
                    )}
                </section>

                <div className="flex flex-wrap items-center gap-3 border-t pt-3">
                    <p className="text-sm text-muted-foreground">
                        {t(':count sign-ins counted.', {
                            count: String(teacher.logins),
                        })}
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={teacher.logins === 0}
                        onClick={() =>
                            router.post(
                                AccessController.resetTeacherLogins.url(
                                    teacher.uuid,
                                ),
                                {},
                                { preserveScroll: true },
                            )
                        }
                        data-test="reset-teacher-logins"
                    >
                        {t('Count from zero')}
                    </Button>
                </div>

                <div className="flex flex-wrap items-center gap-3 border-t pt-3">
                    <p className="text-sm text-muted-foreground">
                        {t(':count remembered devices.', {
                            count: String(teacher.devices),
                        })}
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={teacher.devices === 0}
                        onClick={() =>
                            router.delete(
                                AccessController.forgetTeacherDevices.url(
                                    teacher.uuid,
                                ),
                                { preserveScroll: true },
                            )
                        }
                        data-test="forget-teacher-devices"
                    >
                        {t('Forget the devices')}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

/** A student's appeal: accept or reject, with an answer they read. */
function AppealRow({
    appeal,
}: {
    appeal: AccessBoardProps['appeals'][number];
}) {
    const { t } = useTranslations();
    const moment = useMoment();
    const [response, setResponse] = useState('');
    const decide = (decision: 'accept' | 'reject') =>
        router.put(
            AppealsController.decide.url(appeal.uuid),
            { decision, response },
            { preserveScroll: true },
        );

    return (
        <li className="grid gap-2 rounded-md border p-3" data-test="appeal">
            <p className="text-sm">
                <span className="font-medium">
                    <bdi>{appeal.student}</bdi>
                </span>{' '}
                · <bdi>{appeal.subject}</bdi>{' '}
                {appeal.stage !== null && appeal.section !== null && (
                    <span className="text-muted-foreground">
                        {placeLabel(
                            t,
                            appeal.stage,
                            appeal.section,
                            appeal.divided,
                        )}
                    </span>
                )}
            </p>
            <p className="text-sm whitespace-pre-line" dir="auto">
                {appeal.reason}
            </p>
            <p className="text-xs text-muted-foreground">
                {moment(appeal.created_at)}
            </p>
            <div className="grid gap-1">
                <Label htmlFor={`answer-${appeal.uuid}`} className="text-xs">
                    {t('Answer to the student')}
                </Label>
                <Input
                    id={`answer-${appeal.uuid}`}
                    value={response}
                    onChange={(event) => setResponse(event.target.value)}
                    maxLength={500}
                    dir="auto"
                    className="bg-background"
                />
            </div>
            <div className="flex flex-wrap gap-2">
                <Button
                    size="sm"
                    onClick={() => decide('accept')}
                    data-test="accept-appeal"
                >
                    <Check aria-hidden />
                    {t('Accept')}
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => decide('reject')}
                    data-test="reject-appeal"
                >
                    <X aria-hidden />
                    {t('Reject')}
                </Button>
            </div>
        </li>
    );
}

/** A request waiting for an answer: approve for some hours, or reject. */
function RequestRow({
    request,
}: {
    request: AccessBoardProps['requests'][number];
}) {
    const { t } = useTranslations();
    const moment = useMoment();
    const [hours, setHours] = useState('24');
    const decide = (decision: 'approve' | 'reject') =>
        router.put(
            AccessController.decide.url(request.uuid),
            decision === 'approve'
                ? { decision, hours: Number(hours) }
                : { decision },
            { preserveScroll: true },
        );

    return (
        <li
            className="grid gap-2 rounded-md border p-3"
            data-test="edit-request"
        >
            <p className="text-sm">
                <span className="font-medium">
                    <bdi>{request.teacher}</bdi>
                </span>{' '}
                · <bdi>{request.subject}</bdi>{' '}
                {request.stage !== null && (
                    <span className="text-muted-foreground">
                        {placeLabel(
                            t,
                            request.stage,
                            request.section,
                            request.divided,
                        )}
                    </span>
                )}
            </p>
            <p className="text-sm whitespace-pre-line" dir="auto">
                {request.reason}
            </p>
            <p className="text-xs text-muted-foreground">
                {moment(request.created_at)}
            </p>
            <div className="flex flex-wrap items-end gap-2">
                <div className="grid gap-1">
                    <Label
                        htmlFor={`hours-${request.uuid}`}
                        className="text-xs"
                    >
                        {t('Allow changes for (hours)')}
                    </Label>
                    <Input
                        id={`hours-${request.uuid}`}
                        type="number"
                        inputMode="numeric"
                        min={1}
                        max={8760}
                        value={hours}
                        onChange={(event) => setHours(event.target.value)}
                        dir="ltr"
                        className="w-28 bg-background"
                    />
                </div>
                <Button
                    size="sm"
                    onClick={() => decide('approve')}
                    data-test="approve-request"
                >
                    <Check aria-hidden />
                    {t('Approve')}
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => decide('reject')}
                    data-test="reject-request"
                >
                    <X aria-hidden />
                    {t('Reject')}
                </Button>
            </div>
        </li>
    );
}

/**
 * Who may sign in and change marks (ADR-0037), on the owner's settings,
 * one part per tab (owner's request, 2026-11-19):
 *
 * - teachers: their sign-in rules and editing time;
 * - students: their sign-in rules, what they see in the result, appeals
 *   waiting, and their sign-ins;
 * - permissions: requests to change marks, and each teacher's access.
 */
export default function AccessPanel({
    access,
    studentView = null,
    part,
}: {
    access: AccessBoardProps;
    /** What the student sees and the appeals (ADR-0044). */
    studentView?: ResultSettings | null;
    part: 'teachers' | 'students' | 'permissions';
}) {
    const { t } = useTranslations();
    const moment = useMoment();
    const modeLabel = useModeLabel();
    const [open, setOpen] = useState<string | null>(null);
    const current = access.teachers.find((teacher) => teacher.uuid === open);

    return (
        <section
            className="grid gap-6 rounded-lg border bg-card p-4"
            aria-labelledby="access-title"
            data-test="access-panel"
        >
            <header className="flex flex-wrap items-center gap-3">
                <ShieldCheck className="size-5" aria-hidden />
                <h2 id="access-title" className="font-semibold">
                    {part === 'teachers'
                        ? t("Teachers' sign-in and mark editing")
                        : part === 'students'
                          ? t("Students' sign-in and what they see")
                          : t("Teachers' permissions")}
                </h2>
                {part !== 'permissions' && (
                    <p className="text-sm text-muted-foreground">
                        {t(
                            'Last 24 hours: :teachers refused teacher sign-ins, :students refused student sign-ins, :lockouts lockouts.',
                            {
                                teachers: String(access.failed.teachers),
                                students: String(access.failed.students),
                                lockouts: String(access.failed.lockouts),
                            },
                        )}
                    </p>
                )}
            </header>

            {part !== 'permissions' && (
                <SettingsForm settings={access.settings} who={part} />
            )}

            {/* Saved on its own form (ADR-0044), after the access form:
                forms cannot nest. */}
            {part === 'students' && studentView && (
                <section className="grid max-w-3xl rounded-md border p-3">
                    <StudentViewForm />
                    <StudentViewFields settings={studentView} />
                </section>
            )}

            {part === 'students' && access.appeals.length > 0 && (
                <section className="grid gap-2" aria-labelledby="appeals">
                    <h3
                        id="appeals"
                        className="flex items-center gap-2 font-semibold"
                    >
                        {t("Students' appeals")}
                        <Badge>{access.appeals.length}</Badge>
                    </h3>
                    <ul className="grid gap-2">
                        {access.appeals.map((appeal) => (
                            <AppealRow key={appeal.uuid} appeal={appeal} />
                        ))}
                    </ul>
                </section>
            )}

            {part === 'permissions' && access.requests.length > 0 && (
                <section className="grid gap-2" aria-labelledby="requests">
                    <h3
                        id="requests"
                        className="flex items-center gap-2 font-semibold"
                    >
                        {t('Requests to change marks')}
                        <Badge>{access.requests.length}</Badge>
                    </h3>
                    <ul className="grid gap-2">
                        {access.requests.map((request) => (
                            <RequestRow key={request.uuid} request={request} />
                        ))}
                    </ul>
                </section>
            )}

            {part === 'permissions' && (
                <section
                    className="grid gap-2"
                    aria-labelledby="teacher-access"
                >
                    <h3 id="teacher-access" className="font-semibold">
                        {t("Teachers' access")}
                    </h3>
                    {access.teachers.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('No teachers yet.')}
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <caption className="sr-only">
                                    {t("Teachers' access")}
                                </caption>
                                <thead>
                                    <tr className="text-muted-foreground">
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
                                            {t('Access')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="p-2 text-start font-medium"
                                        >
                                            {t('Sign-ins')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="p-2 text-start font-medium"
                                        >
                                            {t('Last sign-in')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="p-2 text-start font-medium"
                                        >
                                            {t('Refused (24 h)')}
                                        </th>
                                        <th scope="col" className="p-2">
                                            <span className="sr-only">
                                                {t('Actions')}
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {access.teachers.map((teacher) => {
                                        const special = teacher.sections.filter(
                                            (section) =>
                                                section.mode !== null ||
                                                section.edit_until !== null,
                                        ).length;

                                        return (
                                            <tr
                                                key={teacher.uuid}
                                                className="border-t"
                                                data-test="teacher-access-row"
                                            >
                                                <th
                                                    scope="row"
                                                    className="p-2 text-start font-medium"
                                                >
                                                    <bdi>{teacher.name}</bdi>
                                                    {!teacher.active && (
                                                        <Badge
                                                            variant="secondary"
                                                            className="ms-2"
                                                        >
                                                            {t('Stopped')}
                                                        </Badge>
                                                    )}
                                                </th>
                                                <td className="p-2">
                                                    <span
                                                        className={
                                                            teacher.effective_mode ===
                                                            'blocked'
                                                                ? 'text-destructive-text'
                                                                : undefined
                                                        }
                                                    >
                                                        {modeLabel(
                                                            teacher.effective_mode,
                                                        )}
                                                    </span>
                                                    {teacher.mode === null && (
                                                        <span className="block text-xs text-muted-foreground">
                                                            {t(
                                                                'As the department',
                                                            )}
                                                        </span>
                                                    )}
                                                    {special > 0 && (
                                                        <span className="block text-xs text-muted-foreground">
                                                            {t(
                                                                ':count sections set apart',
                                                                {
                                                                    count: String(
                                                                        special,
                                                                    ),
                                                                },
                                                            )}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="p-2 tabular-nums">
                                                    {teacher.logins}
                                                </td>
                                                <td className="p-2">
                                                    {teacher.last_login_at
                                                        ? moment(
                                                              teacher.last_login_at,
                                                          )
                                                        : t('Never')}
                                                </td>
                                                <td className="p-2 tabular-nums">
                                                    {teacher.refused}
                                                </td>
                                                <td className="p-2 text-end">
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            setOpen(
                                                                teacher.uuid,
                                                            )
                                                        }
                                                        data-test="open-teacher-access"
                                                    >
                                                        <KeyRound aria-hidden />
                                                        {/* The visible word starts
                                                        the accessible name
                                                        (WCAG 2.5.3); the name
                                                        tells the rows apart. */}
                                                        {t('Access')}
                                                        <span className="sr-only">
                                                            {' '}
                                                            <bdi>
                                                                {teacher.name}
                                                            </bdi>
                                                        </span>
                                                    </Button>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            )}

            {part === 'students' && (
                <section
                    className="flex flex-wrap items-center gap-3 border-t pt-4"
                    aria-labelledby="student-sign-ins"
                    data-test="student-sign-ins"
                >
                    <h3 id="student-sign-ins" className="font-semibold">
                        {t("Students' sign-ins")}
                    </h3>
                    <p className="text-sm text-muted-foreground">
                        {t(
                            ':signed of :total students signed in, :logins sign-ins in all; :blocked stopped.',
                            {
                                signed: String(access.students.signed_in),
                                total: String(access.students.total),
                                logins: String(access.students.logins),
                                blocked: String(access.students.blocked),
                            },
                        )}
                    </p>
                    <ConfirmAction
                        trigger={
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={access.students.logins === 0}
                                data-test="reset-student-logins"
                            >
                                {t('Count from zero')}
                            </Button>
                        }
                        title={t("Count the students' sign-ins from zero?")}
                        description={t(
                            "Every student's count goes back to 0, so a cap on sign-ins starts again.",
                        )}
                        confirmLabel={t('Count from zero')}
                        tone="default"
                        form={AccessController.resetStudentLogins.form()}
                    />
                </section>
            )}

            {current && (
                <TeacherAccessDialog
                    teacher={current}
                    departmentMode={access.settings.teacher_mode}
                    onClose={() => setOpen(null)}
                />
            )}
        </section>
    );
}
