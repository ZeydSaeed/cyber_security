import { Form, Head, Link } from '@inertiajs/react';
import { useCallback } from 'react';
import GradesAdminController from '@/actions/App/Modules/Grades/Http/GradesAdminController';
import StudentsAdminController from '@/actions/App/Modules/Grades/Http/StudentsAdminController';
import DataTable from '@/components/data-table/data-table';
import type { ColumnDef } from '@/components/data-table/data-table';
import TablePagination from '@/components/data-table/table-pagination';
import TableToolbar from '@/components/data-table/table-toolbar';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTableNavigation } from '@/hooks/use-table-navigation';
import { useTranslations } from '@/hooks/use-translations';
import type { ColumnPrefs, PageMeta, TableState } from '@/types/table';

type StudentRow = {
    id: number;
    name: string;
    number: string | null;
    stage: number;
    section: string;
};

const TABLE_PROPS = ['students', 'state'];

/**
 * The students (ADR-0026, ADR-0034): a server-side list (search by name or
 * number, stage and section filters), and the import from Excel or CSV,
 * which shows a preview before anything changes.
 */
export default function GradesStudents({
    students,
    state,
    columns,
    canImport,
}: {
    students: { rows: StudentRow[] } & PageMeta;
    state: TableState;
    columns: ColumnPrefs;
    canImport: boolean;
}) {
    const { t } = useTranslations();
    const { visit, busy } = useTableNavigation(state, TABLE_PROPS);
    const onSearch = useCallback(
        (search: string) => visit({ search }),
        [visit],
    );
    const filtered =
        state.search !== '' || Object.keys(state.filters).length > 0;

    const columnDefs: ColumnDef<StudentRow>[] = [
        {
            key: 'full_name',
            header: t('Name'),
            sortable: true,
            cell: (s) => <span dir="auto">{s.name}</span>,
        },
        {
            key: 'student_number',
            header: t('Number'),
            sortable: true,
            cell: (s) => (s.number ? <span dir="ltr">{s.number}</span> : null),
        },
        {
            key: 'stage',
            header: t('Stage'),
            sortable: true,
            cell: (s) => s.stage,
        },
        {
            key: 'section',
            header: t('Section'),
            sortable: true,
            cell: (s) => s.section,
        },
    ];

    return (
        <>
            <Head title={t('Students')} />
            <div className="flex flex-col gap-6 p-4">
                <Heading
                    title={t('Students')}
                    description={t('The students of both stages, as imported.')}
                />

                <p className="text-sm">
                    <Link
                        href={GradesAdminController.overview.url()}
                        className="text-muted-foreground underline-offset-4 hover:underline"
                    >
                        {t('First-month marks')}
                    </Link>
                </p>

                {canImport && (
                    <section className="max-w-2xl rounded-lg border bg-card p-4">
                        <h2 className="mb-1 font-semibold">
                            {t('Import students')}
                        </h2>
                        <p className="mb-4 text-sm text-muted-foreground">
                            {t(
                                'An Excel (.xlsx) or CSV file with the columns name, stage (1 or 2), section (A or B) and, if you have it, the student number. You will see what changes before anything is saved.',
                            )}
                        </p>
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

                <TableToolbar
                    search={state.search}
                    filters={[
                        {
                            key: 'stage',
                            label: t('Stage'),
                            options: ['1', '2'].map((value) => ({
                                value,
                                label: t('Stage :stage', { stage: value }),
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
