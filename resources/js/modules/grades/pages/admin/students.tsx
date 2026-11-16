import { Form, Head, router } from '@inertiajs/react';
import { FileDown, UserPlus } from 'lucide-react';
import { useCallback, useState } from 'react';
import StudentsAdminController from '@/actions/App/Modules/Grades/Http/StudentsAdminController';
import ConfirmAction from '@/components/confirm-action';
import DataTable from '@/components/data-table/data-table';
import type { ColumnDef } from '@/components/data-table/data-table';
import TablePagination from '@/components/data-table/table-pagination';
import TableToolbar from '@/components/data-table/table-toolbar';
import InputError from '@/components/input-error';
import PageToolbar, { toolButton } from '@/components/page-toolbar';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTableNavigation } from '@/hooks/use-table-navigation';
import { useTranslations } from '@/hooks/use-translations';
import type { ColumnPrefs, PageMeta, TableState } from '@/types/table';
import { stageLabel, stageName } from '../../place';

type StudentRow = {
    id: number;
    uuid: string;
    has_code: boolean;
    /** The student's code (their ID), shown to those who manage. */
    code: string | null;
    name: string;
    number: string | null;
    stage: number;
    section: string;
};

const TABLE_PROPS = ['students', 'state'];

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-base shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';

/**
 * Adding a student to a stage and section, or changing one: name, stage,
 * section and, for a student already added, their code (ID). A new
 * student's code is made by the application. The first stage has no
 * sections, so the section is asked for the second stage only.
 */
function StudentDialog({
    student,
    open,
    onClose,
}: {
    student: StudentRow | null;
    open: boolean;
    onClose: () => void;
}) {
    const { t } = useTranslations();
    const [stage, setStage] = useState(student?.stage ?? 1);
    const form =
        student === null
            ? StudentsAdminController.store.form()
            : StudentsAdminController.update.form(student.uuid);

    return (
        <Dialog open={open} onOpenChange={(now) => !now && onClose()}>
            <DialogContent className="bg-card" data-test="student-dialog">
                <DialogTitle>
                    {student === null ? t('Add a student') : t('Edit student')}
                </DialogTitle>
                <DialogDescription className="sr-only">
                    {t('Name, code, stage and section.')}
                </DialogDescription>
                <Form
                    {...form}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="grid gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="student-name">
                                    {t('Name')}
                                </Label>
                                <Input
                                    id="student-name"
                                    name="name"
                                    defaultValue={student?.name}
                                    required
                                    maxLength={160}
                                    dir="auto"
                                />
                                <InputError message={errors.name} />
                            </div>
                            {student === null ? (
                                <p className="text-sm text-muted-foreground">
                                    {t(
                                        'The application makes a code for the student when you save.',
                                    )}
                                </p>
                            ) : (
                                <div className="grid gap-2">
                                    <Label htmlFor="student-code">
                                        {t('Code')}
                                    </Label>
                                    <Input
                                        id="student-code"
                                        name="code"
                                        defaultValue={student.code ?? ''}
                                        maxLength={40}
                                        autoComplete="off"
                                        spellCheck={false}
                                        dir="ltr"
                                        className="font-mono uppercase"
                                    />
                                    <InputError message={errors.code} />
                                </div>
                            )}
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="student-stage">
                                        {t('Stage')}
                                    </Label>
                                    <select
                                        id="student-stage"
                                        name="stage"
                                        value={stage}
                                        onChange={(event) =>
                                            setStage(Number(event.target.value))
                                        }
                                        className={selectClass}
                                    >
                                        <option value={1}>
                                            {stageLabel(t, 1)}
                                        </option>
                                        <option value={2}>
                                            {stageLabel(t, 2)}
                                        </option>
                                    </select>
                                    <InputError message={errors.stage} />
                                </div>
                                {stage === 2 && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="student-section">
                                            {t('Section')}
                                        </Label>
                                        <select
                                            id="student-section"
                                            name="section"
                                            defaultValue={
                                                student?.stage === 2
                                                    ? student.section
                                                    : 'A'
                                            }
                                            className={selectClass}
                                        >
                                            <option value="A">A</option>
                                            <option value="B">B</option>
                                        </select>
                                        <InputError message={errors.section} />
                                    </div>
                                )}
                            </div>
                            {/* Refusals not about one field (marks, a clash). */}
                            <InputError message={errors.student} />
                            <div className="flex gap-2">
                                <Button
                                    disabled={processing}
                                    data-test="save-student"
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

/** "Codes of the first stage", or "... second stage · Section A". */
function codesLabel(
    t: ReturnType<typeof useTranslations>['t'],
    place: { stage: number; section: string; divided: boolean },
): string {
    const stage = t('Codes of the :stage stage', {
        stage: place.stage === 1 ? t('First') : t('Second'),
    });

    return place.divided
        ? `${stage} · ${t('Section :section', { section: place.section })}`
        : stage;
}

/**
 * The students (ADR-0026, ADR-0034): a server-side list (search by name,
 * number or code, stage and section filters, both in the top toolbar),
 * each student's code (their ID), stages by name, with a
 * number in order, grid lines, and actions to change or delete each
 * student; one student added by hand, or many imported from Excel or CSV
 * (with a file to fill, and a preview before anything changes).
 */
export default function GradesStudents({
    students,
    state,
    columns,
    canImport,
    withoutCode,
    places,
}: {
    students: { rows: StudentRow[] } & PageMeta;
    state: TableState;
    columns: ColumnPrefs;
    canImport: boolean;
    withoutCode: number;
    places: { stage: number; section: string; divided: boolean }[];
}) {
    const { t } = useTranslations();
    const { visit, busy } = useTableNavigation(state, TABLE_PROPS);
    const onSearch = useCallback(
        (search: string) => visit({ search }),
        [visit],
    );
    const filtered =
        state.search !== '' || Object.keys(state.filters).length > 0;
    // null: closed; 'new': adding; a row: changing that student.
    const [dialog, setDialog] = useState<StudentRow | 'new' | null>(null);

    const columnDefs: ColumnDef<StudentRow>[] = [
        {
            key: 'full_name',
            header: t('Name'),
            sortable: true,
            cell: (s) => <span dir="auto">{s.name}</span>,
        },
        {
            key: 'code',
            header: t('Code'),
            cell: (s) =>
                s.code ? (
                    <span
                        dir="ltr"
                        className="font-mono"
                        data-test="student-code"
                    >
                        {s.code}
                    </span>
                ) : null,
        },
        {
            key: 'stage',
            header: t('Stage'),
            sortable: true,
            cell: (s) => stageName(t, s.stage),
        },
        {
            key: 'section',
            header: t('Section'),
            sortable: true,
            // The first stage has no sections.
            cell: (s) => (s.stage === 1 ? '—' : s.section),
        },
    ];

    return (
        <>
            <Head title={t('Students')} />
            <PageToolbar title={t('Students')}>
                <TableToolbar
                    compact
                    search={state.search}
                    filters={[
                        {
                            key: 'stage',
                            label: t('Stage'),
                            options: ['1', '2'].map((value) => ({
                                value,
                                label: stageLabel(t, Number(value)),
                            })),
                        },
                        {
                            key: 'section',
                            label: t('Section'),
                            options: ['A', 'B'].map((value) => ({
                                value,
                                label: t('Section :section', {
                                    section: value,
                                }),
                            })),
                        },
                    ]}
                    values={state.filters}
                    onSearch={onSearch}
                    onFilter={(key, value) =>
                        visit({
                            filters: { ...state.filters, [key]: value ?? '' },
                        })
                    }
                    onClear={() => visit({ search: '', filters: {} })}
                />
                {canImport && (
                    <Button
                        variant="outline"
                        size="sm"
                        className={`h-8 ${toolButton}`}
                        onClick={() => setDialog('new')}
                        data-test="add-student"
                    >
                        <UserPlus aria-hidden />
                        {t('Add a student')}
                    </Button>
                )}
            </PageToolbar>

            {dialog !== null && (
                <StudentDialog
                    // A fresh form for each student.
                    key={dialog === 'new' ? 'new' : dialog.uuid}
                    student={dialog === 'new' ? null : dialog}
                    open
                    onClose={() => setDialog(null)}
                />
            )}

            <div className="flex flex-col gap-6 p-4">
                {canImport && (
                    <section className="max-w-2xl rounded-lg border bg-card p-4">
                        <h2 className="mb-1 font-semibold">
                            {t('Import students')}
                        </h2>
                        <p className="mb-3 text-sm text-muted-foreground">
                            {t(
                                'An Excel (.xlsx) or CSV file with the columns name, stage (1 or 2), section (A or B) and, if you have it, the student number. You will see what changes before anything is saved.',
                            )}
                        </p>
                        <details
                            className="mb-4 rounded-md border p-3 text-sm"
                            data-test="import-help"
                        >
                            <summary className="cursor-pointer font-medium">
                                {t('How to fill the file')}
                            </summary>
                            <ul className="mt-2 list-disc space-y-1 ps-5">
                                <li>
                                    {t(
                                        "Name: the student's full name, at least 3 letters.",
                                    )}
                                </li>
                                <li>
                                    {t(
                                        'Stage: 1 for the first stage, 2 for the second.',
                                    )}
                                </li>
                                <li>
                                    {t(
                                        'Section: A or B for the second stage; leave it empty for the first stage.',
                                    )}
                                </li>
                                <li>
                                    {t(
                                        'Student number: optional. If you write it, it must not repeat.',
                                    )}
                                </li>
                                <li>
                                    {t(
                                        "One student per row, under the headers. The file's second sheet has an example.",
                                    )}
                                </li>
                            </ul>
                            <Button
                                variant="outline"
                                size="sm"
                                className="mt-3"
                                asChild
                            >
                                <a
                                    href={StudentsAdminController.template.url()}
                                    data-test="import-template"
                                >
                                    <FileDown aria-hidden />
                                    {t('Download the file to fill')}
                                </a>
                            </Button>
                        </details>
                        <Form
                            {...StudentsAdminController.upload.form()}
                            className="flex flex-col gap-3 sm:flex-row sm:items-end"
                            data-test="import-form"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid flex-1 gap-2">
                                        <Label htmlFor="import-file">
                                            {t('File')}
                                        </Label>
                                        <Input
                                            id="import-file"
                                            type="file"
                                            name="file"
                                            accept=".xlsx,.csv"
                                            required
                                        />
                                        <InputError message={errors.file} />
                                    </div>
                                    <Button disabled={processing}>
                                        {t('Preview')}
                                    </Button>
                                </>
                            )}
                        </Form>
                    </section>
                )}

                {canImport && (
                    <section
                        className="max-w-2xl rounded-lg border bg-card p-4"
                        data-test="student-codes"
                    >
                        <h2 className="mb-3 font-semibold">
                            {t('Student codes')}
                        </h2>
                        {withoutCode > 0 && (
                            <div className="mb-4 flex flex-wrap items-center gap-3">
                                <span className="text-sm">
                                    {t(':count students have no code yet.', {
                                        count: String(withoutCode),
                                    })}
                                </span>
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            StudentsAdminController.issueCodes.url(),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                    data-test="issue-codes"
                                >
                                    {t('Give them codes')}
                                </Button>
                            </div>
                        )}
                        <div className="flex flex-wrap gap-2">
                            {places.map((place) => (
                                <Button
                                    key={`${place.stage}${place.section}`}
                                    variant="outline"
                                    size="sm"
                                    asChild
                                >
                                    <a
                                        href={`${StudentsAdminController.codes.url()}?stage=${place.stage}&section=${place.section}`}
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        {codesLabel(t, place)}
                                    </a>
                                </Button>
                            ))}
                        </div>
                    </section>
                )}

                <DataTable
                    caption={t('Students')}
                    columns={columnDefs}
                    prefs={columns}
                    rows={students.rows}
                    rowId={(s) => s.id}
                    rowLabel={(s) => s.name}
                    state={state}
                    busy={busy}
                    filtered={filtered}
                    bordered
                    numberFrom={students.from ?? 1}
                    actionsLabel={canImport ? t('Actions') : undefined}
                    onClearFilters={() => visit({ search: '', filters: {} })}
                    onSort={(column) =>
                        visit({
                            sort: column,
                            direction:
                                state.sort === column &&
                                state.direction === 'asc'
                                    ? 'desc'
                                    : 'asc',
                        })
                    }
                    rowAction={
                        canImport
                            ? (student) => (
                                  <div className="flex flex-wrap justify-start gap-2">
                                      <Button
                                          variant="outline"
                                          size="sm"
                                          onClick={() => setDialog(student)}
                                          data-test="edit-student"
                                      >
                                          {t('Edit')}
                                      </Button>
                                      <Button
                                          variant="outline"
                                          size="sm"
                                          onClick={() =>
                                              router.post(
                                                  StudentsAdminController.replaceCode.url(
                                                      student.uuid,
                                                  ),
                                                  {},
                                                  { preserveScroll: true },
                                              )
                                          }
                                      >
                                          {student.has_code
                                              ? t('New code')
                                              : t('Give a code')}
                                      </Button>
                                      <ConfirmAction
                                          trigger={
                                              <Button
                                                  variant="outline"
                                                  size="sm"
                                                  className="text-destructive-text"
                                                  data-test="delete-student"
                                              >
                                                  {t('Delete')}
                                              </Button>
                                          }
                                          title={t('Delete :name?', {
                                              name: student.name,
                                          })}
                                          description={t(
                                              'A student who already has marks cannot be deleted.',
                                          )}
                                          confirmLabel={t('Delete')}
                                          form={StudentsAdminController.destroy.form(
                                              student.uuid,
                                          )}
                                      />
                                  </div>
                              )
                            : undefined
                    }
                />

                <TablePagination
                    meta={students}
                    perPage={state.per_page}
                    onPage={(page) => visit({ page })}
                    onPerPage={(per_page) => visit({ per_page })}
                />
            </div>
        </>
    );
}
