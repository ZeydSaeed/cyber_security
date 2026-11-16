import { Form, Head, router } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';
import { Fragment, useState } from 'react';
import GradesAdminController from '@/actions/App/Modules/Grades/Http/GradesAdminController';
import ConfirmAction from '@/components/confirm-action';
import EmptyState from '@/components/empty-state';
import InputError from '@/components/input-error';
import PageToolbar, { toolButton } from '@/components/page-toolbar';
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
import { stageLabel } from '../../place';

type Teacher = {
    uuid: string;
    name: string;
    /** The employee number, or null when none is kept yet. */
    code: string | null;
    active: boolean;
    last_seen_at: string | null;
};

type Subject = {
    uuid: string;
    name: string;
    stage: number;
    divided: boolean;
    sections: string[];
    teacher: string | null;
};

/**
 * Adding a teacher, or changing one: name, employee number and the
 * subjects they teach. A subject chosen here moves to this teacher from
 * whoever had it.
 */
function TeacherDialog({
    teacher,
    teachers,
    subjects,
    codeLength,
    onClose,
}: {
    teacher: Teacher | null;
    teachers: Teacher[];
    subjects: Subject[];
    codeLength: { min: number; max: number };
    onClose: () => void;
}) {
    const { t } = useTranslations();
    const form =
        teacher === null
            ? GradesAdminController.storeTeacher.form()
            : GradesAdminController.updateTeacher.form(teacher.uuid);
    const nameOf = (uuid: string | null) =>
        teachers.find((other) => other.uuid === uuid)?.name ?? null;
    const stages = [...new Set(subjects.map((subject) => subject.stage))];

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto bg-card sm:max-w-2xl"
                data-test="teacher-dialog"
            >
                <DialogTitle>
                    {teacher === null ? t('Add a teacher') : t('Edit teacher')}
                </DialogTitle>
                <DialogDescription className="sr-only">
                    {t('Name, employee number and subjects.')}
                </DialogDescription>
                <Form
                    {...form}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="grid gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="teacher-name">
                                        {t('Name')}
                                    </Label>
                                    <Input
                                        id="teacher-name"
                                        name="name"
                                        defaultValue={teacher?.name}
                                        required
                                        maxLength={120}
                                        dir="auto"
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="teacher-code">
                                        {t('Employee number')}
                                    </Label>
                                    <Input
                                        id="teacher-code"
                                        name="code"
                                        defaultValue={teacher?.code ?? ''}
                                        required
                                        minLength={codeLength.min}
                                        maxLength={codeLength.max}
                                        autoComplete="off"
                                        spellCheck={false}
                                        dir="ltr"
                                    />
                                    <InputError message={errors.code} />
                                </div>
                            </div>

                            <fieldset className="grid gap-3">
                                <legend className="mb-2 text-sm font-medium">
                                    {t('Subjects they teach')}
                                </legend>
                                {/* Sent even when none is chosen: "none". */}
                                <input
                                    type="hidden"
                                    name="subjects[]"
                                    value=""
                                />
                                {subjects.length === 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        {t('No subjects yet.')}
                                    </p>
                                )}
                                {stages.map((stage) => (
                                    <div key={stage} className="grid gap-2">
                                        <p className="text-sm font-semibold">
                                            {stageLabel(t, stage)}
                                        </p>
                                        <div className="grid gap-2 sm:grid-cols-2">
                                            {subjects
                                                .filter(
                                                    (subject) =>
                                                        subject.stage === stage,
                                                )
                                                .map((subject) => {
                                                    const other =
                                                        subject.teacher !==
                                                            null &&
                                                        subject.teacher !==
                                                            teacher?.uuid
                                                            ? nameOf(
                                                                  subject.teacher,
                                                              )
                                                            : null;

                                                    return (
                                                        <label
                                                            key={subject.uuid}
                                                            className="flex items-start gap-2 text-sm"
                                                        >
                                                            <input
                                                                type="checkbox"
                                                                name="subjects[]"
                                                                value={
                                                                    subject.uuid
                                                                }
                                                                defaultChecked={
                                                                    teacher !==
                                                                        null &&
                                                                    subject.teacher ===
                                                                        teacher.uuid
                                                                }
                                                                className="mt-1 size-4 accent-primary"
                                                            />
                                                            <span>
                                                                <bdi>
                                                                    {
                                                                        subject.name
                                                                    }
                                                                </bdi>
                                                                {other && (
                                                                    <span className="block text-muted-foreground">
                                                                        {t(
                                                                            'Now taught by :name',
                                                                            {
                                                                                name: other,
                                                                            },
                                                                        )}
                                                                    </span>
                                                                )}
                                                            </span>
                                                        </label>
                                                    );
                                                })}
                                        </div>
                                    </div>
                                ))}
                                <InputError message={errors.subjects} />
                            </fieldset>

                            <div className="flex gap-2">
                                <Button
                                    disabled={processing}
                                    data-test="save-teacher"
                                >
                                    {t('Save')}
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={onClose}
                                >
                                    {t('Cancel')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Teachers in one table (owner's request, 2026-11-16): the number in
 * order, the name, the employee number (the teacher's code to sign in),
 * the subjects they teach with each one's stage and sections, and the
 * actions: edit, stop or allow signing in, delete. "Add a teacher" is in
 * the top toolbar. Employee numbers are kept encrypted; showing this page
 * is audited. Changing a number or stopping a teacher ends their sessions.
 */
export default function GradesTeachers({
    teachers,
    subjects,
    codeLength,
}: {
    teachers: Teacher[];
    subjects: Subject[];
    codeLength: { min: number; max: number };
}) {
    const { t, locale } = useTranslations();
    // null: closed; 'new': adding; a teacher: changing them.
    const [dialog, setDialog] = useState<Teacher | 'new' | null>(null);
    const list = new Intl.ListFormat(locale === 'ar' ? 'ar' : 'en', {
        type: 'conjunction',
    });
    const taught = (teacher: Teacher) =>
        subjects.filter((subject) => subject.teacher === teacher.uuid);
    // One line per subject, so the subject, stage and section line up.
    const lines = (teacher: Teacher, line: (subject: Subject) => string) => {
        const own = taught(teacher);

        return own.length === 0 ? (
            <span className="text-muted-foreground">—</span>
        ) : (
            own.map((subject) => (
                <Fragment key={subject.uuid}>
                    <bdi className="block">{line(subject)}</bdi>
                </Fragment>
            ))
        );
    };

    return (
        <>
            <Head title={t('Teachers')} />
            <PageToolbar title={t('Teachers')}>
                <Button
                    variant="outline"
                    size="sm"
                    className={`h-7 ${toolButton}`}
                    onClick={() => setDialog('new')}
                    data-test="add-teacher"
                >
                    <UserPlus aria-hidden />
                    {t('Add a teacher')}
                </Button>
            </PageToolbar>

            {dialog !== null && (
                <TeacherDialog
                    key={dialog === 'new' ? 'new' : dialog.uuid}
                    teacher={dialog === 'new' ? null : dialog}
                    teachers={teachers}
                    subjects={subjects}
                    codeLength={codeLength}
                    onClose={() => setDialog(null)}
                />
            )}

            <div className="flex flex-col gap-6 p-4">
                {teachers.length === 0 ? (
                    <EmptyState
                        title={t('No teachers yet')}
                        description={t(
                            'Add each teacher with their employee number.',
                        )}
                    />
                ) : (
                    <div
                        className="overflow-x-auto rounded-xl border border-grid-line bg-card focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                        tabIndex={0}
                        role="region"
                        aria-label={t('Teachers')}
                    >
                        <table
                            className="w-full min-w-[48rem] border-collapse border-hidden text-sm [&_td]:border [&_td]:border-grid-line [&_th]:border [&_th]:border-grid-line"
                            data-test="teachers"
                        >
                            <caption className="sr-only">
                                {t('Teachers')}
                            </caption>
                            <thead>
                                <tr>
                                    <th
                                        scope="col"
                                        className="w-12 px-4 py-3 text-start"
                                    >
                                        #
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-4 py-3 text-start"
                                    >
                                        {t('Name')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-4 py-3 text-start"
                                    >
                                        {t('Employee number')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-4 py-3 text-start"
                                    >
                                        {t('Subject')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-4 py-3 text-start"
                                    >
                                        {t('Stage')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-4 py-3 text-start"
                                    >
                                        {t('Section')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-4 py-3 text-start"
                                    >
                                        {t('Actions')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {teachers.map((teacher, index) => (
                                    <tr
                                        key={teacher.uuid}
                                        data-test="teacher-row"
                                    >
                                        <td className="px-4 py-3 align-top text-muted-foreground tabular-nums">
                                            {index + 1}
                                        </td>
                                        <th
                                            scope="row"
                                            className="px-4 py-3 text-start align-top font-medium"
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
                                        <td className="px-4 py-3 align-top">
                                            {teacher.code ? (
                                                <span
                                                    dir="ltr"
                                                    className="font-mono"
                                                    data-test="teacher-code"
                                                >
                                                    {teacher.code}
                                                </span>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    {t(
                                                        'Not kept: set a new one',
                                                    )}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 align-top">
                                            {lines(
                                                teacher,
                                                (subject) => subject.name,
                                            )}
                                        </td>
                                        <td className="px-4 py-3 align-top">
                                            {lines(teacher, (subject) =>
                                                stageLabel(t, subject.stage),
                                            )}
                                        </td>
                                        <td className="px-4 py-3 align-top">
                                            {lines(teacher, (subject) =>
                                                subject.divided
                                                    ? list.format(
                                                          subject.sections,
                                                      )
                                                    : t('All students'),
                                            )}
                                        </td>
                                        <td className="px-4 py-3 align-top">
                                            <div className="flex flex-wrap gap-2">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        setDialog(teacher)
                                                    }
                                                    data-test="edit-teacher"
                                                >
                                                    {t('Edit')}
                                                </Button>
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        router.put(
                                                            GradesAdminController.updateTeacher.url(
                                                                teacher.uuid,
                                                            ),
                                                            {
                                                                active: !teacher.active,
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                    data-test="toggle-teacher"
                                                >
                                                    {teacher.active
                                                        ? t('Stop signing in')
                                                        : t('Allow signing in')}
                                                </Button>
                                                <ConfirmAction
                                                    trigger={
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            className="text-destructive-text"
                                                            data-test="delete-teacher"
                                                        >
                                                            {t('Delete')}
                                                        </Button>
                                                    }
                                                    title={t('Delete :name?', {
                                                        name: teacher.name,
                                                    })}
                                                    description={t(
                                                        'Their subjects are left without a teacher. A teacher who has entered marks cannot be deleted; stop their signing in instead.',
                                                    )}
                                                    confirmLabel={t('Delete')}
                                                    form={GradesAdminController.destroyTeacher.form(
                                                        teacher.uuid,
                                                    )}
                                                />
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}
