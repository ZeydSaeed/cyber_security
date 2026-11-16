import { Form, Head, router } from '@inertiajs/react';
import { Printer, UserPlus } from 'lucide-react';
import { Fragment, useCallback, useState } from 'react';
import GradesAdminController from '@/actions/App/Modules/Grades/Http/GradesAdminController';
import ConfirmAction from '@/components/confirm-action';
import TableToolbar from '@/components/data-table/table-toolbar';
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
    /** As the school's staff list writes it, e.g. "Electricity". */
    specialty: string | null;
    /** The code to sign in, made by the application; null if none kept. */
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
    onClose,
}: {
    teacher: Teacher | null;
    teachers: Teacher[];
    subjects: Subject[];
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
                    {t('Name, specialty and subjects.')}
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
                                    <Label htmlFor="teacher-specialty">
                                        {t('Specialty')}
                                    </Label>
                                    <Input
                                        id="teacher-specialty"
                                        name="specialty"
                                        defaultValue={teacher?.specialty ?? ''}
                                        maxLength={80}
                                        dir="auto"
                                    />
                                    <InputError message={errors.specialty} />
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
 * the top toolbar. Codes are made by the application ("New code", after a
 * confirmation) and kept encrypted; showing this page
 * is audited. Changing a number or stopping a teacher ends their sessions.
 */
export default function GradesTeachers({
    teachers,
    subjects,
}: {
    teachers: Teacher[];
    subjects: Subject[];
}) {
    const { t, locale } = useTranslations();
    // null: closed; 'new': adding; a teacher: changing them.
    const [dialog, setDialog] = useState<Teacher | 'new' | null>(null);
    // Search and filter on this page (all teachers are on it).
    const [search, setSearch] = useState('');
    const [specialty, setSpecialty] = useState<string | null>(null);
    const onSearch = useCallback((text: string) => setSearch(text), []);
    const specialties = [
        ...new Set(
            teachers
                .map((teacher) => teacher.specialty)
                .filter((value): value is string => value !== null),
        ),
    ].sort((a, b) => a.localeCompare(b, locale));
    const needle = search.trim().toLowerCase();
    const shown = teachers.filter(
        (teacher) =>
            (specialty === null || teacher.specialty === specialty) &&
            (needle === '' ||
                [teacher.name, teacher.specialty ?? '', teacher.code ?? '']
                    .join(' ')
                    .toLowerCase()
                    .includes(needle)),
    );
    const list = new Intl.ListFormat(locale === 'ar' ? 'ar' : 'en', {
        type: 'conjunction',
    });
    const taught = (teacher: Teacher) =>
        subjects.filter((subject) => subject.teacher === teacher.uuid);
    // One line per subject, so the subject, stage and section line up.
    const lines = (teacher: Teacher, line: (subject: Subject) => string) => {
        const own = taught(teacher);

        return own.length === 0
            ? null
            : own.map((subject) => (
                  <Fragment key={subject.uuid}>
                      <bdi className="block min-h-5 whitespace-nowrap">
                          {line(subject)}
                      </bdi>
                  </Fragment>
              ));
    };

    return (
        <>
            <Head title={t('Teachers')} />
            {/* The same toolbar as the students' and subjects' pages:
                title, search and filter, then the actions. */}
            <PageToolbar title={t('Teachers')}>
                <TableToolbar
                    compact
                    search={search}
                    filters={[
                        {
                            key: 'specialty',
                            label: t('Specialty'),
                            options: specialties.map((value) => ({
                                value,
                                label: value,
                            })),
                        },
                    ]}
                    values={specialty === null ? {} : { specialty }}
                    onSearch={onSearch}
                    onFilter={(_key, value) => setSpecialty(value)}
                    onClear={() => {
                        setSearch('');
                        setSpecialty(null);
                    }}
                />
                <Button
                    variant="outline"
                    size="sm"
                    className={`h-8 ${toolButton}`}
                    onClick={() => setDialog('new')}
                    data-test="add-teacher"
                >
                    <UserPlus aria-hidden />
                    {t('Add a teacher')}
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    className={`h-8 ${toolButton}`}
                    asChild
                >
                    <a
                        href={GradesAdminController.printTeachers.url()}
                        target="_blank"
                        rel="noopener"
                        data-test="print-teachers"
                    >
                        <Printer aria-hidden />
                        {t('Print')}
                    </a>
                </Button>
            </PageToolbar>

            {dialog !== null && (
                <TeacherDialog
                    key={dialog === 'new' ? 'new' : dialog.uuid}
                    teacher={dialog === 'new' ? null : dialog}
                    teachers={teachers}
                    subjects={subjects}
                    onClose={() => setDialog(null)}
                />
            )}

            <div className="flex flex-col gap-6 p-4">
                {shown.length === 0 && teachers.length > 0 ? (
                    <EmptyState
                        kind="no-results"
                        title={t('No results')}
                        description={t('Nothing matches these filters.')}
                    />
                ) : teachers.length === 0 ? (
                    <EmptyState
                        title={t('No teachers yet')}
                        description={t(
                            'Add each teacher; the application makes their code.',
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
                                        className="w-12 px-4 py-3 text-center"
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
                                        className="px-4 py-3 text-center"
                                    >
                                        {t('Specialty')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-4 py-3 text-center"
                                    >
                                        {t('Code')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-4 py-3 text-start"
                                    >
                                        {t('Subject')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-4 py-3 text-center"
                                    >
                                        {t('Stage')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-4 py-3 text-center"
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
                                {shown.map((teacher, index) => (
                                    <tr
                                        key={teacher.uuid}
                                        data-test="teacher-row"
                                    >
                                        <td className="px-4 py-3 text-center align-top text-muted-foreground tabular-nums">
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
                                        <td className="px-4 py-3 text-center align-top">
                                            {teacher.specialty && (
                                                <bdi>{teacher.specialty}</bdi>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-center align-top">
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
                                                        'None yet: make a new one',
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
                                        <td className="px-4 py-3 text-center align-top">
                                            {lines(teacher, (subject) =>
                                                stageLabel(t, subject.stage),
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-center align-top">
                                            {lines(teacher, (subject) =>
                                                subject.divided
                                                    ? list.format(
                                                          subject.sections,
                                                      )
                                                    : '',
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
                                                <ConfirmAction
                                                    trigger={
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            data-test="new-teacher-code"
                                                        >
                                                            {t('New code')}
                                                        </Button>
                                                    }
                                                    title={t(
                                                        'A new code for :name?',
                                                        { name: teacher.name },
                                                    )}
                                                    description={t(
                                                        'The current code will be cancelled at once and a new one made. Give the teacher the new code.',
                                                    )}
                                                    confirmLabel={t(
                                                        'Make a new code',
                                                    )}
                                                    tone="default"
                                                    form={GradesAdminController.regenerateTeacherCode.form(
                                                        teacher.uuid,
                                                    )}
                                                />
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
