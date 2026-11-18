<?php

namespace App\Http\Controllers\Admin;

use App\Access\Permission;
use App\Access\Role;
use App\Actions\Users\CreateUser;
use App\Actions\Users\DeleteUser;
use App\Actions\Users\UpdateUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Tables\UsersTable;
use App\Models\User;
use App\Platform\Access\RoleAssignment;
use App\Platform\Audit\ActivityTimeline;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tables\SavedView;
use App\Platform\Tables\TableColumns;
use App\Platform\Tables\TableState;
use BackedEnum;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Users for administrators (roles.manage): a server-side table (#2) with the
 * person's columns (#3) and saved views (#4); one user's details and history
 * in a side panel (#16, #9), loaded only when the panel opens.
 *
 * Owner's request (2026-11-18): administrators add, change and delete
 * people here, grant roles and permissions directly, and can show the
 * password they set. A password is shown only on request, one at a time,
 * and each showing is audited.
 */
class UserController extends Controller
{
    public function index(Request $request, UsersTable $table, #[CurrentUser] User $viewer): Response
    {
        $state = TableState::from($table, $request->query());
        $users = $state->paginate($table, User::query()->with(['roleAssignments', 'permissionGrants']));

        return Inertia::render('admin/users/index', [
            'users' => [
                'rows' => $users->getCollection()->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->roleAssignments->pluck('role')->sort()->values()->all(),
                    'permissions' => $user->directPermissions(),
                    'password_state' => $user->adminPasswordState(),
                    'password_set_at' => $user->admin_password_set_at?->toIso8601String(),
                ])->all(),
                'page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'total' => $users->total(),
                'from' => $users->firstItem(),
                'to' => $users->lastItem(),
            ],
            'state' => $state->toArray(),
            'columns' => TableColumns::for($viewer->id, $table),
            'filterOptions' => $table->filters(),
            'roles' => array_map(fn (Role $role): string => $role->value, Role::cases()),
            // What each role grants, shown beside the permission boxes.
            'rolePermissions' => array_combine(
                array_map(fn (Role $role): string => $role->value, Role::cases()),
                array_map(fn (Role $role): array => array_map(fn (BackedEnum $permission): string => (string) $permission->value, $role->permissions()), Role::cases()),
            ),
            'permissions' => array_map(fn (Permission $permission): string => $permission->value, Permission::cases()),
            'viewerId' => $viewer->id,
            'views' => SavedView::query()
                ->where('user_id', $viewer->id)
                ->where('table', $table->name())
                ->orderBy('name')
                ->get(['id', 'name', 'state'])
                ->map(fn (SavedView $view): array => ['id' => $view->id, 'name' => $view->name, 'state' => $view->state])
                ->all(),
            // Only when a user is opened (?user=ID or a partial reload).
            'selected' => fn (): ?array => $this->details($request->integer('user'), $viewer),
        ]);
    }

    public function store(StoreUserRequest $request, CreateUser $create, #[CurrentUser] User $actor): RedirectResponse
    {
        $user = $create->handle(
            $actor,
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->roles(),
            $request->permissions(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name was added.', ['name' => $user->name])]);

        return to_route('admin.users.index');
    }

    public function update(StoreUserRequest $request, User $user, UpdateUser $update, #[CurrentUser] User $actor): RedirectResponse
    {
        $update->handle(
            $actor,
            $user,
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->filled('password') ? $request->string('password')->toString() : null,
            $request->roles(),
            $request->permissions(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name was updated.', ['name' => $user->name])]);

        return back();
    }

    public function destroy(User $user, DeleteUser $delete, #[CurrentUser] User $actor): RedirectResponse
    {
        $delete->handle($actor, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name was deleted.', ['name' => $user->name])]);

        return to_route('admin.users.index');
    }

    /**
     * The password an administrator set, decrypted, for the eye button.
     * POST, so it is never prefetched or cached, and audited each time.
     */
    public function password(User $user, AuditLogger $audit, #[CurrentUser] User $actor): JsonResponse
    {
        $state = $user->adminPasswordState();
        $password = null;

        if ($state === 'set') {
            try {
                // As the model's `encrypted` cast does, but here, so a
                // copy made under an earlier application key is caught.
                $password = Crypt::decryptString((string) $user->getRawOriginal('admin_password'));
            } catch (DecryptException) {
                // Encrypted with an earlier application key.
                $state = 'unknown';
            }

            $audit->record('user.password_viewed', $user, actor: $actor);
        }

        return response()
            ->json(['state' => $state, 'password' => $password])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function details(int $id, User $viewer): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $user = User::query()->with(['roleAssignments', 'permissionGrants'])->find($id);

        if ($user === null) {
            return null;
        }

        $canSeeHistory = $viewer->hasPermission(Permission::AuditView);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null,
            'created_at' => $user->created_at?->toIso8601String(),
            'roles' => $user->roleAssignments->map(fn (RoleAssignment $assignment): array => [
                'role' => $assignment->role,
                'since' => $assignment->created_at->toIso8601String(),
            ])->values()->all(),
            'permissions' => $user->directPermissions(),
            'is_viewer' => $user->is($viewer),
            'timeline' => $canSeeHistory ? ActivityTimeline::for($user, includeActions: true) : null,
        ];
    }
}
