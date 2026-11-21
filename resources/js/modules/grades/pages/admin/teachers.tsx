import { Form, Head, router } from '@inertiajs/react';
import {
    Lock,
    LockOpen,
    Pencil,
    Printer,
    RefreshCw,
    Trash2,
    UserPlus,
} from 'lucide-react';
import { Fragment, useCallback, useId, useState } from 'react';
import type { ReactNode } from 'react';
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
import { kindLabel } from '../../result-words';
import { kindOf, teachesPractical, teachesTheory } from '../../types';
import type { TeachingKind } from '../../types';
import {
    rowAction,
    rowActions,
    rowActionTone,
} from '../../components/row-actions';

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
    /** Each section's teacher (a teacher's UUID, or null for none). */
    teachers: Record<string, string | null>;
    /** How each section is taught: theory, practical, both, or not said. */
    kinds: Record<string, TeachingKind | null>;
    /** Each section's assistant teacher (a teacher's UUID), if any. */
    assistants: Record<string, string | null>;
};

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-base shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';

/** The sections of a subject this teacher teaches. */
const ownSections = (subject: Subject, uuid: string) =>
    subject.sections.filter((section) => subject.teachers[section] === uuid);

/**
 * One subject in the teacher's form. A subject of an undivided stage is
 * one box; a divided stage's subject has a box per section, since each
 * section may have its own teacher. Each box sends "<subject>:<section>".
 */
function SubjectChoice({
    subject,
    teacher,
    teachers,
    nameOf,
}: {
    subject: Subject;
    teacher: Teacher | null;
    teachers: Teacher[];
    nameOf: (uuid: string | null) => string | null;
}) {
    const { t } = useTranslations();

    if (!subject.divided) {
        return (
            <SectionChoice
                subject={subject}
                section={subject.sections[0]}
                label={<bdi>{subject.name}</bdi>}
                teacher={teacher}
                teachers={teachers}
                nameOf={nameOf}
            />
        );
    }

    return (
        <div
            role="group"
            aria-label={subject.name}
            className="grid gap-2 rounded-md border border-grid-line p-2"
        >
            <p className="text-sm font-medium">
                <bdi>{subject.name}</bdi>
            </p>
            {subject.sections.map((section) => (
                <SectionChoice
                    key={section}
                    subject={subject}
                    section={section}
                    label={t('Section :section', { section })}
                    teacher={teacher}
                    teachers={teachers}
                    nameOf={nameOf}
                />
            ))}
        </div>
    );
}

/**
 * One section's box and, once ticked, how it is taught and the teacher's
 * assistant, another teacher (owner's request, 2026-11-18). The type is two
 * boxes, theoretical and practical, either or both (owner's request,
 * 2026-11-20); none ticked is "not stated". An unticked section sends
 * neither.
 */
function SectionChoice({
    subject,
    section,
    label,
    teacher,
    teachers,
    nameOf,
}: {
    subject: Subject;
    section: string;
    label: ReactNode;
    teacher: Teacher | null;
    teachers: Teacher[];
    nameOf: (uuid: string | null) => string | null;
}) {
    const { t } = useTranslations();
    const id = useId();
    const own = teacher !== null && subject.teachers[section] === teacher.uuid;
    const [checked, setChecked] = useState(own);
    // Kept only for the teacher who had the section.
    const given = own ? (subject.kinds[section] ?? null) : null;
    const [theory, setTheory] = useState(teachesTheory(given));
    const [practical, setPractical] = useState(teachesPractical(given));
    const holder = subject.teachers[section] ?? null;
    const taughtBy =
        holder !== null && holder !== teacher?.uuid ? nameOf(holder) : null;
    const key = `details[${subject.uuid}:${section}]`;

    return (
        <div className="grid gap-2" data-test="subject-section">
            <label className="flex items-start gap-2 text-sm">
                <input
                    type="checkbox"
                    name="subjects[]"
                    value={`${subject.uuid}:${section}`}
                    checked={checked}
                    onChange={(event) => setChecked(event.target.checked)}
                    className="mt-1 size-4 accent-primary"
                />
                <span>
                    {label}
                    {taughtBy && (
                        <span className="block text-muted-foreground">
                            {t('Now taught by :name', { name: taughtBy })}
                        </span>
                    )}
                </span>
            </label>
            {checked && (
                <div className="grid gap-2 ps-6" data-test="section-details">
                    <fieldset className="grid gap-1" data-test="section-kind">
                        <legend className="mb-1 text-xs font-medium">
                            {t('Type')}
                        </legend>
                        <input
                            type="hidden"
                            name={`${key}[kind]`}
                            value={kindOf(theory, practical) ?? ''}
                        />
                        <div className="flex flex-wrap gap-x-6 gap-y-1">
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={theory}
                                    onChange={(event) =>
                                        setTheory(event.target.checked)
                                    }
                                    className="size-4 accent-primary"
                                    data-test="section-kind-theory"
                                />
                                {t('Theoretical')}
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={practical}
                                    onChange={(event) =>
                                        setPractical(event.target.checked)
                                    }
                                    className="size-4 accent-primary"
                                    data-test="section-kind-practical"
                                />
                                {t('Practical')}
                            </label>
                        </div>
                    </fieldset>
                    <div className="grid gap-1">
                        <Label htmlFor={`${id}-assistant`} className="text-xs">
                            {t('Assistant teacher name')}
                        </Label>
                        <select
                            id={`${id}-assistant`}
                            name={`${key}[assistant]`}
                            defaultValue={
                                own ? (subject.assistants[section] ?? '') : ''
                            }
                            className={selectClass}
                            data-test="section-assistant"
                        >
                            <option value="">{t('No assistant')}</option>
                            {teachers
                                .filter((other) => other.uuid !== teacher?.uuid)
                                .map((other) => (
                                    <option key={other.uuid} value={other.uuid}>
                                        {other.name}
                                    </option>
                                ))}
                        </select>
                    </div>
                </div>
            )}
        </div>
    );
}

/**
 * Adding a teacher, or changing one: name, employee number and the
 * subjects they teach, with the sections of a divided stage (owner's
 * request, 2026-11-18). A section chosen here moves to this teacher from
 * whoever had it; the teacher then sees that section's students.
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
                                                .map((subject) => (
                                                    <SubjectChoice
                                                        key={subject.uuid}
                                                        subject={subject}
                                                        teacher={teacher}
                                                        teachers={teachers}
                                                        nameOf={nameOf}
                                                    />
                                                ))}
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
    const taught = (teacher: Teacher) =>
        subjects.flatMap((subject) =>
            ownSections(subject, teacher.uuid).map((section) => ({
                subject,
                section,
            })),
        );
    // One line per section taught, so the subject, stage, section, type
    // and assistant line up.
    const lines = (
        teacher: Teacher,
        line: (subject: Subject, section: string) => string,
    ) => {
        const own = taught(teacher);

        return own.length === 0
            ? null
            : own.map(({ subject, section }) => (
                  <Fragment key={`${subject.uuid}:${section}`}>
                      <bdi className="block min-h-5 whitespace-nowrap">
                          {line(subject, section)}
                      </bdi>
                  </Fragment>
              ));
    };
    const nameOf = (uuid: string | null) =>
        teachers.find((other) => other.uuid === uuid)?.name ?? '';

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
                        className="w-fit max-w-full overflow-x-auto rounded-xl border border-grid-line bg-card focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                        tabIndex={0}
                        role="region"
                        aria-label={t('Teachers')}
                    >
                        <table
                            className="border-collapse border-hidden text-sm [&_td]:border [&_td]:border-grid-line [&_td]:whitespace-nowrap [&_th]:border [&_th]:border-grid-line [&_th]:whitespace-nowrap"
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
                                        className="px-4 py-3 text-center"
                                    >
                                        {t('Type')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-4 py-3 text-start"
                                    >
                                        {t('Assistant teacher')}
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
                                            {lines(
                                                teacher,
                                                (subject, section) =>
                                                    subject.divided
                                                        ? section
                                                        : '',
                                            )}
                                        </td>
                                        <td
                                            className="px-4 py-3 text-center align-top"
                                            data-test="teacher-kinds"
                                        >
                                            {lines(
                                                teacher,
                                                (subject, section) => {
                                                    const kind =
                                                        subject.kinds[section];

                                                    return kind
                                                        ? kindLabel(t, kind)
                                                        : '';
                                                },
                                            )}
                                        </td>
                                        <td
                                            className="px-4 py-3 align-top"
                                            data-test="teacher-assistants"
                                        >
                                            {/* Shown only when there is one. */}
                                            {lines(
                                                teacher,
                                                (subject, section) =>
                                                    nameOf(
                                                        subject.assistants[
                                                            section
                                                        ] ?? null,
                                                    ),
                                            )}
                                        </td>
                                        <td className="px-4 py-3 align-top">
                                            {/* Icon-only actions with no
                                                background (row-actions.ts);
                                                the label is the accessible
                                                name and the hover hint. */}
                                            <div className={rowActions}>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className={`${rowAction} ${rowActionTone.info}`}
                                                    aria-label={t('Edit')}
                                                    title={t('Edit')}
                                                    onClick={() =>
                                                        setDialog(teacher)
                                                    }
                                                    data-test="edit-teacher"
                                                >
                                                    <Pencil aria-hidden />
                                                </Button>
                                                <ConfirmAction
                                                    trigger={
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className={`${rowAction} ${rowActionTone.warning}`}
                                                            aria-label={t(
                                                                'New code',
                                                            )}
                                                            title={t(
                                                                'New code',
                                                            )}
                                                            data-test="new-teacher-code"
                                                        >
                                                            <RefreshCw
                                                                aria-hidden
                                                            />
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
                                                {/* Shows what a click does, as on
                                                    the students' page: a closed
                                                    lock (amber) stops signing
                                                    in, an open one (green)
                                                    allows it. */}
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className={`${rowAction} ${
                                                        teacher.active
                                                            ? rowActionTone.warning
                                                            : rowActionTone.success
                                                    }`}
                                                    aria-label={
                                                        teacher.active
                                                            ? t(
                                                                  'Stop signing in',
                                                              )
                                                            : t(
                                                                  'Allow signing in',
                                                              )
                                                    }
                                                    title={
                                                        teacher.active
                                                            ? t(
                                                                  'Stop signing in',
                                                              )
                                                            : t(
                                                                  'Allow signing in',
                                                              )
                                                    }
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
                                                    {teacher.active ? (
                                                        <Lock aria-hidden />
                                                    ) : (
                                                        <LockOpen aria-hidden />
                                                    )}
                                                </Button>
                                                <ConfirmAction
                                                    trigger={
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className={`${rowAction} ${rowActionTone.destructive}`}
                                                            aria-label={t(
                                                                'Delete',
                                                            )}
                                                            title={t('Delete')}
                                                            data-test="delete-teacher"
                                                        >
                                                            <Trash2
                                                                aria-hidden
                                                            />
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
