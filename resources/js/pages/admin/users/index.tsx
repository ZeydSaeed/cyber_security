import { Head, router, usePage } from '@inertiajs/react';
import {
    Eye,
    Pencil,
    ShieldMinus,
    ShieldPlus,
    Trash2,
    UserPlus,
} from 'lucide-react';
import { lazy, Suspense, useCallback, useState } from 'react';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import UserRoleController from '@/actions/App/Http/Controllers/Admin/UserRoleController';
import SavedViewController from '@/actions/App/Http/Controllers/Tables/SavedViewController';
import TableColumnsController from '@/actions/App/Http/Controllers/Tables/TableColumnsController';
import { roleLabel } from '@/components/activity-timeline';
import PasswordCell from '@/components/admin/password-cell';
import type { PasswordState } from '@/components/admin/password-cell';
import type { SelectedUser } from '@/components/admin/user-details';
import type { EditableUser } from '@/components/admin/user-form-dialog';
import ConfirmAction from '@/components/confirm-action';
import {
    BulkActionBar,
    BulkResultPanel,
} from '@/components/data-table/bulk-actions';
import ColumnSettings from '@/components/data-table/column-settings';
import DataTable from '@/components/data-table/data-table';
import type { ColumnDef } from '@/components/data-table/data-table';
import SavedViews from '@/components/data-table/saved-views';
import TablePagination from '@/components/data-table/table-pagination';
import TableToolbar from '@/components/data-table/table-toolbar';
import Drawer from '@/components/drawer';
import Heading from '@/components/heading';
import { SkeletonText } from '@/components/skeletons';
import { Button } from '@/components/ui/button';
import { useTableNavigation } from '@/hooks/use-table-navigation';
import { useTranslations } from '@/hooks/use-translations';
import { index as usersIndex } from '@/routes/admin/users';
import type {
    BulkResult,
    ColumnPrefs,
    PageMeta,
    SavedViewItem,
    TableState,
} from '@/types/table';

type UserRow = EditableUser & {
    password_state: PasswordState;
    /** Changes whenever an administrator sets a new password. */
    password_set_at: string | null;
};

type Props = {
    users: { rows: UserRow[] } & PageMeta;
    state: TableState;
    columns: ColumnPrefs;
    filterOptions: Record<string, string[]>;
    roles: string[];
    rolePermissions: Record<string, string[]>;
    permissions: string[];
    viewerId: number;
    views: SavedViewItem[];
    selected: SelectedUser | null;
};

const TABLE_PROPS = ['users', 'state'];

// Used only after a click: loaded then, to keep the list page small.
const UserDetails = lazy(() => import('@/components/admin/user-details'));
const UserFormDialog = lazy(
    () => import('@/components/admin/user-form-dialog'),
);
const BulkRoleDialog = lazy(
    () => import('@/components/admin/bulk-role-dialog'),
);

/**
 * Users (owner's request, 2026-11-18): username, email address, password
 * (shown with the eye, one at a time) and the actions View, Edit and
 * Delete; adding a person with their roles and permissions.
 */
export default function UsersIndex({
    users,
    state,
    columns,
    roles,
    rolePermissions,
    permissions,
    viewerId,
    views,
    selected,
}: Props) {
    const { t } = useTranslations();
    const { url } = usePage();
    const { visit, busy } = useTableNavigation(state, TABLE_PROPS);
    const [selection, setSelection] = useState<number[]>([]);
    const [result, setResult] = useState<BulkResult | null>(null);
    const [bulk, setBulk] = useState<'assign' | 'revoke' | null>(null);
    const [openingId, setOpeningId] = useState<number | null>(null);
    const [editing, setEditing] = useState<UserRow | 'new' | null>(null);

    const columnDefs: ColumnDef<UserRow>[] = [
        {
            key: 'name',
            header: t('Username'),
            sortable: true,
            cell: (user) => user.name,
        },
        {
            key: 'email',
            header: t('Email address'),
            sortable: true,
            cell: (user) => <span dir="ltr">{user.email}</span>,
        },
        {
            key: 'password',
            header: t('Password'),
            cell: (user) => (
                <PasswordCell
                    key={`${user.id}:${user.password_state}:${user.password_set_at}`}
                    userId={user.id}
                    userName={user.name}
                    state={user.password_state}
                />
            ),
        },
    ];
    const hideable = new Set(['email', 'password']);

    const onSearch = useCallback(
        (search: string) => visit({ search }),
        [visit],
    );

    const openUser = (id: number | null) => {
        setOpeningId(id);
        const query = Object.fromEntries(
            new URL(url, window.location.origin).searchParams,
        );
        delete query.user;

        router.get(
            window.location.pathname,
            id === null ? query : { ...query, user: String(id) },
            {
                only: ['selected'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => setOpeningId(null),
            },
        );
    };

    const runBulk = (role: string) => {
        if (!bulk) {
            return;
        }

        router.post(
            UserRoleController.url(),
            { action: bulk, role, ids: selection },
            {
                only: ['users', 'selected'],
                preserveState: true,
                preserveScroll: true,
                onFlash: (flash) => setResult(flash.bulkResult ?? null),
                onSuccess: () => {
                    setBulk(null);
                    setSelection([]);
                },
            },
        );
    };

    const filtered =
        state.search !== '' || Object.keys(state.filters).length > 0;

    return (
        <>
            <Head title={t('Users')} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={t('Users')}
                        description={t(
                            'People who can sign in, and their permissions.',
                        )}
                    />
                    <Button
                        onClick={() => setEditing('new')}
                        data-test="add-user"
                    >
                        <UserPlus aria-hidden />
                        {t('Add a user')}
                    </Button>
                </div>

                <TableToolbar
                    search={state.search}
                    values={state.filters}
                    filters={[
                        {
                            key: 'role',
                            label: t('Role'),
                            options: [
                                ...roles.map((role) => ({
                                    value: role,
                                    label: roleLabel(t, role),
                                })),
                                { value: 'none', label: t('No role') },
                            ],
                        },
                    ]}
                    onSearch={onSearch}
                    onFilter={(key, value) => {
                        const filters = { ...state.filters };

                        if (value === null) {
                            delete filters[key];
                        } else {
                            filters[key] = value;
                        }

                        visit({ filters });
                    }}
                    onClear={() => visit({ search: '', filters: {} })}
                >
                    <ColumnSettings
                        columns={columnDefs.map((column) => ({
                            key: column.key,
                            header: column.header,
                            hideable: hideable.has(column.key),
                        }))}
                        prefs={columns}
                        onSave={(prefs) =>
                            router.put(
                                TableColumnsController.url('users'),
                                prefs,
                                {
                                    only: ['columns'],
                                    preserveState: true,
                                    preserveScroll: true,
                                },
                            )
                        }
                    />
                    <SavedViews
                        views={views}
                        state={state}
                        columns={columns}
                        saveUrl={SavedViewController.store.url('users')}
                        deleteForm={(view) =>
                            SavedViewController.destroy.form({
                                table: 'users',
                                view: view.id,
                            })
                        }
                        onApply={(view) => {
                            if (view.state.columns) {
                                router.put(
                                    TableColumnsController.url('users'),
                                    view.state.columns,
                                    {
                                        only: ['columns'],
                                        preserveState: true,
                                        preserveScroll: true,
                                        onSuccess: () =>
                                            visit({ ...view.state }),
                                    },
                                );
                            } else {
                                visit({ ...view.state });
                            }
                        }}
                    />
                </TableToolbar>

                <BulkActionBar
                    count={selection.length}
                    onClear={() => setSelection([])}
                >
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setBulk('assign')}
                        data-test="bulk-assign"
                    >
                        <ShieldPlus aria-hidden />
                        {t('Grant a role')}
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setBulk('revoke')}
                        data-test="bulk-revoke"
                    >
                        <ShieldMinus aria-hidden />
                        {t('Remove a role')}
                    </Button>
                </BulkActionBar>

                {result && (
                    <BulkResultPanel
                        result={result}
                        onDismiss={() => setResult(null)}
                    />
                )}

                <DataTable
                    caption={t('Users')}
                    columns={columnDefs}
                    prefs={columns}
                    rows={users.rows}
                    rowId={(user) => user.id}
                    rowLabel={(user) => user.name}
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
                    selected={selection}
                    onSelectedChange={setSelection}
                    actionsLabel={t('Actions')}
                    rowAction={(user) => (
                        <span className="inline-flex gap-1">
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => openUser(user.id)}
                                aria-label={t('View :name', {
                                    name: user.name,
                                })}
                                data-test="open-user"
                            >
                                <Eye aria-hidden />
                                {t('View')}
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => setEditing(user)}
                                aria-label={t('Edit :name', {
                                    name: user.name,
                                })}
                                data-test="edit-user"
                            >
                                <Pencil aria-hidden />
                                {t('Edit')}
                            </Button>
                            {user.id !== viewerId && (
                                <ConfirmAction
                                    trigger={
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="text-destructive-text"
                                            aria-label={t('Delete :name', {
                                                name: user.name,
                                            })}
                                            data-test="delete-user"
                                        >
                                            <Trash2 aria-hidden />
                                            {t('Delete')}
                                        </Button>
                                    }
                                    title={t('Delete :name?', {
                                        name: user.name,
                                    })}
                                    description={t(
                                        'The account and its access are removed. The audit trail keeps what they did.',
                                    )}
                                    confirmLabel={t('Delete')}
                                    form={UserController.destroy.form(user.id)}
                                />
                            )}
                        </span>
                    )}
                />

                <TablePagination
                    meta={users}
                    perPage={state.per_page}
                    onPage={(page) => visit({ page })}
                    onPerPage={(perPage) => visit({ per_page: perPage })}
                />
            </div>

            <Drawer
                open={selected !== null || openingId !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        openUser(null);
                    }
                }}
                title={selected?.name ?? t('Loading')}
                description={selected?.email}
            >
                {selected === null ? (
                    <SkeletonText lines={6} />
                ) : (
                    <Suspense fallback={<SkeletonText lines={6} />}>
                        <UserDetails user={selected} />
                    </Suspense>
                )}
            </Drawer>

            {editing !== null && (
                <Suspense fallback={null}>
                    <UserFormDialog
                        key={editing === 'new' ? 'new' : editing.id}
                        user={editing === 'new' ? null : editing}
                        roles={roles}
                        permissions={permissions}
                        rolePermissions={rolePermissions}
                        isViewer={editing !== 'new' && editing.id === viewerId}
                        onClose={() => setEditing(null)}
                    />
                </Suspense>
            )}

            {bulk !== null && (
                <Suspense fallback={null}>
                    <BulkRoleDialog
                        action={bulk}
                        roles={roles}
                        count={selection.length}
                        onConfirm={runBulk}
                        onClose={() => setBulk(null)}
                    />
                </Suspense>
            )}
        </>
    );
}

UsersIndex.layout = {
    breadcrumbs: [{ title: 'Users', href: usersIndex() }],
};
