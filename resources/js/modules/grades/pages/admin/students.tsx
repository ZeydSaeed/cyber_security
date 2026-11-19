import { Form, Head, Link, router } from '@inertiajs/react';
import {
    KeyRound,
    Lock,
    MessageSquareOff,
    MessageSquareText,
    LockOpen,
    MonitorX,
    Pencil,
    Printer,
    RefreshCw,
    Trash2,
    UserPlus,
} from 'lucide-react';
import { useCallback, useState } from 'react';
import AccessController from '@/actions/App/Modules/Grades/Http/AccessController';
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
    /** 'external' (intisab), or null for a regular student. */
    status: string | null;
    /** Sign-ins counted, the last one, and whether stopped (ADR-0037). */
    logins: number;
    last_login_at: string | null;
    blocked: boolean;
    /** May appeal when appeals are for chosen students (ADR-0043). */
    appeal_allowed: boolean;
    /** Remembered devices not expired (ADR-0038). */
    devices: number;
};

const TABLE_PROPS = ['students', 'state'];

/**
 * Row actions coloured by what they do (owner's request, 2026-11-18):
 * edit = info, a first code = success, replacing a code = warning,
 * delete = danger. Status tokens only (COLOR-DESIGN-SYSTEM); each button
 * also carries an icon and its word, so colour is never the only cue.
 */
const actionTone = {
    info: 'border-info/40 text-info-text hover:bg-info/10 hover:text-info-text',
    success:
        'border-success/40 text-success-text hover:bg-success/10 hover:text-success-text',
    warning:
        'border-warning/60 text-warning-text hover:bg-warning/15 hover:text-warning-text',
    destructive:
        'border-destructive/40 text-destructive-text hover:bg-destructive/10 hover:text-destructive-text',
} as const;

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-base shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';

/**
 * Adding a student to a stage and section, or changing one: name, stage,
 * section and, for a student already added, their code (ID). A new
 * student's code is made by the application. The section is asked only
 * for a stage divided into sections (the server says which).
 */
function StudentDialog({
    student,
    stages,
    open,
    onClose,
}: {
    student: StudentRow | null;
    stages: { stage: number; divided: boolean }[];
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
                    {t('Name, code, stage, section and status.')}
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
                                        {stages.map((option) => (
                                            <option
                                                key={option.stage}
                                                value={option.stage}
                                            >
                                                {stageLabel(t, option.stage)}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.stage} />
                                </div>
                                {stages.some(
                                    (option) =>
                                        option.stage === stage &&
                                        option.divided,
                                ) && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="student-section">
                                            {t('Section')}
                                        </Label>
                                        <select
                                            id="student-section"
                                            name="section"
                                            defaultValue={
                                                student?.stage === stage
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
                            <div className="grid gap-2">
                                <Label htmlFor="student-status">
                                    {t('Student status')}
                                </Label>
                                <select
                                    id="student-status"
                                    name="status"
                                    defaultValue={student?.status ?? ''}
                                    className={selectClass}
                                >
                                    <option value="">{t('Regular')}</option>
                                    <option value="external">
                                        {t('External')}
                                    </option>
                                </select>
                                <InputError message={errors.status} />
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
    places,
    appealScope = 'all',
}: {
    students: { rows: StudentRow[] } & PageMeta;
    state: TableState;
    columns: ColumnPrefs;
    canImport: boolean;
    places: { stage: number; section: string; divided: boolean }[];
    /** Every student may appeal, or only those allowed here (ADR-0043). */
    appealScope?: 'all' | 'selected';
}) {
    const { t } = useTranslations();
    const { visit, busy } = useTableNavigation(state, TABLE_PROPS);
    const onSearch = useCallback(
        (search: string) => visit({ search }),
        [visit],
    );
    const filtered =
        state.search !== '' || Object.keys(state.filters).length > 0;
    // Each stage once, and whether it has sections (from the server).
    const stages = places.filter(
        (place, i) => places.findIndex((p) => p.stage === place.stage) === i,
    );
    const divided = (stage: number) =>
        stages.some((place) => place.stage === stage && place.divided);
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
            className: 'text-center',
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
            className: 'text-center',
            cell: (s) => stageName(t, s.stage),
        },
        {
            key: 'section',
            header: t('Section'),
            sortable: true,
            className: 'text-center',
            // A stage without sections shows none.
            cell: (s) => (divided(s.stage) ? s.section : '—'),
        },
        {
            key: 'status',
            header: t('Student status'),
            sortable: true,
            className: 'text-center',
            // Empty for a regular student (owner's request, 2026-11-16).
            cell: (s) => (s.status === 'external' ? t('External') : null),
        },
        {
            key: 'login_count',
            header: t('Sign-ins'),
            sortable: true,
            className: 'text-center tabular-nums',
            cell: (s) => (
                <span data-test="student-logins">
                    {s.logins}
                    {s.blocked && (
                        <span className="block text-xs text-destructive-text">
                            {t('Stopped')}
                        </span>
                    )}
                </span>
            ),
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
                            options: stages.map((place) => ({
                                value: String(place.stage),
                                label: stageLabel(t, place.stage),
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
                {/* The code slips, on a page of their own (owner,
                    2026-11-17), as the teachers' page prints its slips. */}
                {canImport && (
                    <Button
                        variant="outline"
                        size="sm"
                        className={`h-8 ${toolButton}`}
                        asChild
                    >
                        <Link
                            href={StudentsAdminController.printMenu.url()}
                            data-test="print-students"
                        >
                            <Printer aria-hidden />
                            {t('Print')}
                        </Link>
                    </Button>
                )}
            </PageToolbar>

            {dialog !== null && (
                <StudentDialog
                    stages={stages}
                    // A fresh form for each student.
                    key={dialog === 'new' ? 'new' : dialog.uuid}
                    student={dialog === 'new' ? null : dialog}
                    open
                    onClose={() => setDialog(null)}
                />
            )}

            <div className="flex flex-col gap-6 p-4">
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
                                          className={actionTone.info}
                                          onClick={() => setDialog(student)}
                                          data-test="edit-student"
                                      >
                                          <Pencil aria-hidden />
                                          {t('Edit')}
                                      </Button>
                                      {/* Asks first: the old code stops
                                          working (owner, 2026-11-16). */}
                                      <ConfirmAction
                                          trigger={
                                              <Button
                                                  variant="outline"
                                                  size="sm"
                                                  // Replacing cancels a code
                                                  // in use: a warning. A
                                                  // first code: a success.
                                                  className={
                                                      student.has_code
                                                          ? actionTone.warning
                                                          : actionTone.success
                                                  }
                                                  data-test="new-student-code"
                                              >
                                                  {student.has_code ? (
                                                      <RefreshCw aria-hidden />
                                                  ) : (
                                                      <KeyRound aria-hidden />
                                                  )}
                                                  {student.has_code
                                                      ? t('New code')
                                                      : t('Give a code')}
                                              </Button>
                                          }
                                          title={t('A new code for :name?', {
                                              name: student.name,
                                          })}
                                          description={t(
                                              'The current code will be cancelled at once and a new one made. Give the student the new code.',
                                          )}
                                          confirmLabel={t('Make a new code')}
                                          tone="default"
                                          form={StudentsAdminController.replaceCode.form(
                                              student.uuid,
                                          )}
                                      />
                                      {student.devices > 0 && (
                                          <Button
                                              variant="outline"
                                              size="sm"
                                              className={actionTone.warning}
                                              onClick={() =>
                                                  router.delete(
                                                      AccessController.forgetStudentDevices.url(
                                                          student.uuid,
                                                      ),
                                                      { preserveScroll: true },
                                                  )
                                              }
                                              data-test="forget-student-devices"
                                          >
                                              <MonitorX aria-hidden />
                                              {t('Forget the devices')}
                                          </Button>
                                      )}
                                      <Button
                                          variant="outline"
                                          size="sm"
                                          className={
                                              student.blocked
                                                  ? actionTone.success
                                                  : actionTone.warning
                                          }
                                          onClick={() =>
                                              router.put(
                                                  AccessController.student.url(
                                                      student.uuid,
                                                  ),
                                                  { blocked: !student.blocked },
                                                  { preserveScroll: true },
                                              )
                                          }
                                          data-test="toggle-student-access"
                                      >
                                          {student.blocked ? (
                                              <LockOpen aria-hidden />
                                          ) : (
                                              <Lock aria-hidden />
                                          )}
                                          {student.blocked
                                              ? t('Allow signing in')
                                              : t('Stop signing in')}
                                      </Button>
                                      {appealScope === 'selected' && (
                                          <Button
                                              variant="outline"
                                              size="sm"
                                              className={
                                                  student.appeal_allowed
                                                      ? actionTone.warning
                                                      : actionTone.info
                                              }
                                              aria-pressed={
                                                  student.appeal_allowed
                                              }
                                              onClick={() =>
                                                  router.put(
                                                      AccessController.studentAppeal.url(
                                                          student.uuid,
                                                      ),
                                                      {
                                                          allowed:
                                                              !student.appeal_allowed,
                                                      },
                                                      { preserveScroll: true },
                                                  )
                                              }
                                              data-test="toggle-student-appeal"
                                          >
                                              {student.appeal_allowed ? (
                                                  <MessageSquareOff
                                                      aria-hidden
                                                  />
                                              ) : (
                                                  <MessageSquareText
                                                      aria-hidden
                                                  />
                                              )}
                                              {student.appeal_allowed
                                                  ? t('Withdraw the appeal')
                                                  : t('Allow an appeal')}
                                          </Button>
                                      )}
                                      <ConfirmAction
                                          trigger={
                                              <Button
                                                  variant="outline"
                                                  size="sm"
                                                  className={
                                                      actionTone.destructive
                                                  }
                                                  data-test="delete-student"
                                              >
                                                  <Trash2 aria-hidden />
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
