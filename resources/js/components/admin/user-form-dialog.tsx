import { Form } from '@inertiajs/react';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import { permissionLabel, roleLabel } from '@/lib/access-labels';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
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

export type EditableUser = {
    id: number;
    name: string;
    email: string;
    roles: string[];
    permissions: string[];
};

const boxClass =
    'flex items-start gap-3 rounded-lg border px-3 py-2 text-sm has-[:checked]:border-primary has-[:checked]:bg-accent';

/**
 * Adding a person, or changing one (owner's request, 2026-11-18): name,
 * address, password (with show/hide; blank keeps it when changing), roles
 * and permissions granted directly, each ticked from the full list. A
 * role's permissions are shown under it, so the two are not confused.
 */
export default function UserFormDialog({
    user,
    roles,
    permissions,
    rolePermissions,
    isViewer,
    onClose,
}: {
    user: EditableUser | null;
    roles: string[];
    permissions: string[];
    rolePermissions: Record<string, string[]>;
    isViewer: boolean;
    onClose: () => void;
}) {
    const { t } = useTranslations();
    const form =
        user === null
            ? UserController.store.form()
            : UserController.update.form(user.id);

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto bg-card sm:max-w-2xl"
                data-test="user-dialog"
            >
                <DialogTitle>
                    {user === null ? t('Add a user') : t('Edit user')}
                </DialogTitle>
                <DialogDescription className="sr-only">
                    {t('Name, email address, password and permissions.')}
                </DialogDescription>
                <Form
                    {...form}
                    options={{ preserveScroll: true }}
                    resetOnError={['password']}
                    onSuccess={onClose}
                    className="grid gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="user-name">
                                        {t('Username')}
                                    </Label>
                                    <Input
                                        id="user-name"
                                        name="name"
                                        defaultValue={user?.name}
                                        required
                                        maxLength={255}
                                        autoComplete="off"
                                        dir="auto"
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="user-email">
                                        {t('Email address')}
                                    </Label>
                                    <Input
                                        id="user-email"
                                        name="email"
                                        type="email"
                                        defaultValue={user?.email}
                                        required
                                        maxLength={255}
                                        autoComplete="off"
                                        dir="ltr"
                                    />
                                    <InputError message={errors.email} />
                                </div>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="user-password">
                                    {user === null
                                        ? t('Password')
                                        : t('New password')}
                                </Label>
                                <PasswordInput
                                    id="user-password"
                                    name="password"
                                    required={user === null}
                                    autoComplete="new-password"
                                    dir="ltr"
                                    aria-describedby="user-password-hint"
                                />
                                <p
                                    id="user-password-hint"
                                    className="text-xs text-muted-foreground"
                                >
                                    {user === null
                                        ? t(
                                              'At least 12 characters, with upper and lower case letters, a number and a symbol.',
                                          )
                                        : t(
                                              'Leave it empty to keep the current password.',
                                          )}
                                </p>
                                <InputError message={errors.password} />
                            </div>

                            {isViewer && (
                                <p className="rounded-md border p-3 text-sm text-muted-foreground">
                                    {t(
                                        'You cannot change your own roles or permissions.',
                                    )}
                                </p>
                            )}

                            <fieldset
                                className="grid gap-2"
                                disabled={isViewer}
                            >
                                <legend className="mb-2 text-sm font-medium">
                                    {t('Roles')}
                                </legend>
                                {/* Sent even when none is ticked: "none". */}
                                <input type="hidden" name="roles[]" value="" />
                                <div className="grid gap-2 sm:grid-cols-2">
                                    {roles.map((role) => (
                                        <label key={role} className={boxClass}>
                                            <input
                                                type="checkbox"
                                                name="roles[]"
                                                value={role}
                                                defaultChecked={user?.roles.includes(
                                                    role,
                                                )}
                                                className="mt-0.5 size-4 accent-primary"
                                                data-test={`role-${role}`}
                                            />
                                            <span className="grid gap-0.5">
                                                <span className="font-medium">
                                                    {roleLabel(t, role)}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {(
                                                        rolePermissions[role] ??
                                                        []
                                                    )
                                                        .map((permission) =>
                                                            permissionLabel(
                                                                t,
                                                                permission,
                                                            ),
                                                        )
                                                        .join('، ')}
                                                </span>
                                            </span>
                                        </label>
                                    ))}
                                </div>
                                <InputError
                                    message={errors.roles ?? errors.role}
                                />
                            </fieldset>

                            <fieldset
                                className="grid gap-2"
                                disabled={isViewer}
                            >
                                <legend className="mb-2 text-sm font-medium">
                                    {t('Permissions')}
                                </legend>
                                <p className="text-xs text-muted-foreground">
                                    {t(
                                        'Granted directly, in addition to those of the roles.',
                                    )}
                                </p>
                                <input
                                    type="hidden"
                                    name="permissions[]"
                                    value=""
                                />
                                <div className="grid gap-2 sm:grid-cols-2">
                                    {permissions.map((permission) => (
                                        <label
                                            key={permission}
                                            className={boxClass}
                                        >
                                            <input
                                                type="checkbox"
                                                name="permissions[]"
                                                value={permission}
                                                defaultChecked={user?.permissions.includes(
                                                    permission,
                                                )}
                                                className="mt-0.5 size-4 accent-primary"
                                                data-test={`permission-${permission}`}
                                            />
                                            {permissionLabel(t, permission)}
                                        </label>
                                    ))}
                                </div>
                                <InputError message={errors.permissions} />
                            </fieldset>

                            <div className="flex gap-2">
                                <Button
                                    disabled={processing}
                                    data-test="save-user"
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
