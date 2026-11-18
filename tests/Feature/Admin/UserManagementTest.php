<?php

use App\Access\Permission;
use App\Access\Role;
use App\Models\User;
use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Platform\Access\PermissionGrant;
use App\Platform\Access\RoleAssignment;
use App\Platform\Audit\AuditEvent;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Grades\GradesFixtures;

/*
| Managing people from the user list (owner's request, 2026-11-18):
| editing (with a new password or none), deleting, permissions granted
| directly, and showing the password an administrator set (decided: the
| copy is cleared when the person changes their own password).
*/

uses(RefreshDatabase::class);

const SET_PASSWORD = 'Str0ng!Passw0rd-x';
const OTHER_PASSWORD = 'An0ther!Passw0rd-y';

function manager(): User
{
    $user = User::factory()->withTwoFactor()->create(['name' => 'Admin']);
    RoleAssignment::query()->forceCreate(['user_id' => $user->id, 'role' => Role::Administrator->value]);

    return $user->refresh();
}

function addedBy(User $admin, array $extra = []): User
{
    test()->actingAs($admin)->post('/admin/users', [
        'name' => 'Huda',
        'email' => 'huda@example.test',
        'password' => SET_PASSWORD,
        ...$extra,
    ])->assertRedirect('/admin/users');

    return User::query()->where('email', 'huda@example.test')->sole();
}

describe('the table', function () {
    it('lists username, email and the password state, never the password', function () {
        $admin = manager();
        addedBy($admin);

        $this->actingAs($admin)
            ->get('/admin/users?search=huda')
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.rows.0.name', 'Huda')
                ->where('users.rows.0.email', 'huda@example.test')
                ->where('users.rows.0.password_state', 'set')
                ->missing('users.rows.0.password')
                ->missing('users.rows.0.admin_password')
                ->where('permissions', array_map(fn (Permission $p): string => $p->value, Permission::cases())));

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertDontSee(SET_PASSWORD);
    });

    it('shows a user whose password was never set here as unknown', function () {
        $admin = manager();

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertInertia(fn (Assert $page) => $page->where('users.rows.0.password_state', 'unknown'));
    });
});

describe('showing a password', function () {
    it('returns the password an administrator set, and audits it', function () {
        $admin = manager();
        $user = addedBy($admin);

        expect($user->getRawOriginal('admin_password'))->not->toBe(SET_PASSWORD)->not->toBeNull();

        $this->actingAs($admin)
            ->postJson("/admin/users/{$user->id}/password")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['state' => 'set', 'password' => SET_PASSWORD]);

        expect(AuditEvent::query()->where('action', 'user.password_viewed')->where('subject_id', (string) $user->id)->count())->toBe(1);
    });

    it('reads a copy made under an earlier application key as unknown', function () {
        $admin = manager();
        $user = addedBy($admin);
        $old = new Encrypter(Encrypter::generateKey('aes-256-cbc'), 'aes-256-cbc');
        DB::table('users')->where('id', $user->id)->update(['admin_password' => $old->encryptString(SET_PASSWORD)]);

        $this->actingAs($admin)
            ->postJson("/admin/users/{$user->id}/password")
            ->assertOk()
            ->assertExactJson(['state' => 'unknown', 'password' => null]);
    });

    it('is for administrators only', function () {
        $admin = manager();
        $user = addedBy($admin);
        $auditor = User::factory()->withTwoFactor()->create();
        RoleAssignment::query()->forceCreate(['user_id' => $auditor->id, 'role' => Role::Auditor->value]);

        $this->actingAs($auditor->refresh())->postJson("/admin/users/{$user->id}/password")->assertForbidden();
    });

    it('clears the copy when the person changes their own password', function () {
        $admin = manager();
        $user = addedBy($admin);

        $user->forceFill(['password' => OTHER_PASSWORD])->save();

        expect($user->refresh()->admin_password)->toBeNull()
            ->and($user->adminPasswordState())->toBe('changed');

        $this->actingAs($admin)
            ->postJson("/admin/users/{$user->id}/password")
            ->assertExactJson(['state' => 'changed', 'password' => null]);
    });
});

describe('editing', function () {
    it('changes name, email and password, and keeps the password when blank', function () {
        $admin = manager();
        $user = addedBy($admin);

        $this->actingAs($admin)
            ->put("/admin/users/{$user->id}", ['name' => 'Huda S', 'email' => 'huda.s@example.test', 'password' => '', 'roles' => [''], 'permissions' => ['']])
            ->assertSessionHasNoErrors();

        $user->refresh();
        expect($user->name)->toBe('Huda S')
            ->and($user->email)->toBe('huda.s@example.test')
            ->and(Hash::check(SET_PASSWORD, $user->password))->toBeTrue()
            ->and($user->admin_password)->toBe(SET_PASSWORD);

        $this->actingAs($admin)
            ->put("/admin/users/{$user->id}", ['name' => 'Huda S', 'email' => 'huda.s@example.test', 'password' => OTHER_PASSWORD])
            ->assertSessionHasNoErrors();

        $user->refresh();
        expect(Hash::check(OTHER_PASSWORD, $user->password))->toBeTrue()
            ->and($user->admin_password)->toBe(OTHER_PASSWORD);

        $events = AuditEvent::query()->where('action', 'user.updated')->where('subject_id', (string) $user->id)->get();
        expect($events)->toHaveCount(2)
            ->and(json_encode($events->toArray()))->not->toContain(OTHER_PASSWORD)->not->toContain(SET_PASSWORD);
    });

    it('grants and removes roles and permissions by ticking them', function () {
        $admin = manager();
        $user = addedBy($admin);

        $this->actingAs($admin)
            ->put("/admin/users/{$user->id}", ['name' => 'Huda', 'email' => 'huda@example.test', 'roles' => ['auditor'], 'permissions' => ['grades.view_results']])
            ->assertSessionHasNoErrors();

        $user->refresh();
        expect($user->hasRole(Role::Auditor))->toBeTrue()
            ->and($user->directPermissions())->toBe(['grades.view_results'])
            ->and($user->hasPermission(Permission::GradesViewResults))->toBeTrue()
            ->and($user->hasPermission(Permission::GradesManage))->toBeFalse()
            ->and(AuditEvent::query()->where('action', 'access.permission_granted')->count())->toBe(1);

        $this->actingAs($admin)
            ->put("/admin/users/{$user->id}", ['name' => 'Huda', 'email' => 'huda@example.test', 'roles' => [''], 'permissions' => ['']])
            ->assertSessionHasNoErrors();

        $user->refresh();
        expect($user->roleAssignments()->exists())->toBeFalse()
            ->and(PermissionGrant::query()->where('user_id', $user->id)->exists())->toBeFalse()
            ->and(AuditEvent::query()->where('action', 'access.permission_revoked')->count())->toBe(1);
    });

    it('opens the pages a direct permission allows, and shares it with the interface', function () {
        $admin = manager();
        // With a second factor, which the platform requires before any page.
        $user = User::factory()->withTwoFactor()->create();

        $this->actingAs($admin)
            ->put("/admin/users/{$user->id}", ['name' => $user->name, 'email' => $user->email, 'permissions' => ['roles.manage']])
            ->assertSessionHasNoErrors();

        $this->actingAs($user->refresh())
            ->get('/admin/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.permissions', ['roles.manage']));
    });

    it('refuses unknown permissions', function () {
        $admin = manager();
        $user = addedBy($admin);

        $this->actingAs($admin)
            ->put("/admin/users/{$user->id}", ['name' => 'Huda', 'email' => 'huda@example.test', 'permissions' => ['everything']])
            ->assertSessionHasErrors('permissions.0');
    });

    it('lets administrators edit their own name but never their own access', function () {
        $admin = manager();

        $this->actingAs($admin)
            ->put("/admin/users/{$admin->id}", ['name' => 'Boss', 'email' => $admin->email, 'roles' => [''], 'permissions' => ['audit.view']])
            ->assertSessionHasNoErrors();

        $admin->refresh();
        expect($admin->name)->toBe('Boss')
            ->and($admin->hasRole(Role::Administrator))->toBeTrue()
            ->and($admin->directPermissions())->toBe([]);
    });

    it('refuses an address another person uses', function () {
        $admin = manager();
        $user = addedBy($admin);

        $this->actingAs($admin)
            ->put("/admin/users/{$user->id}", ['name' => 'Huda', 'email' => $admin->email])
            ->assertSessionHasErrors('email');
    });

    it('keeps the last administrator', function () {
        $admin = manager();
        $other = addedBy($admin, ['roles' => ['administrator']]);

        // Two administrators: removing one is allowed.
        $this->actingAs($admin)
            ->put("/admin/users/{$other->id}", ['name' => 'Huda', 'email' => 'huda@example.test', 'roles' => ['']])
            ->assertSessionHasNoErrors();

        expect($other->refresh()->hasRole(Role::Administrator))->toBeFalse();
    });
});

describe('deleting', function () {
    it('deletes a person with their access, and audits it', function () {
        $admin = manager();
        $user = addedBy($admin, ['roles' => ['auditor'], 'permissions' => ['grades.manage']]);

        $this->actingAs($admin)->delete("/admin/users/{$user->id}")->assertRedirect('/admin/users');

        expect(User::query()->whereKey($user->id)->exists())->toBeFalse()
            ->and(RoleAssignment::query()->where('user_id', $user->id)->exists())->toBeFalse()
            ->and(PermissionGrant::query()->where('user_id', $user->id)->exists())->toBeFalse()
            ->and(AuditEvent::query()->where('action', 'user.deleted')->where('subject_id', (string) $user->id)->exists())->toBeTrue();
    });

    it('refuses deleting oneself and the last administrator', function () {
        $admin = manager();

        $this->actingAs($admin)->delete("/admin/users/{$admin->id}")->assertSessionHasErrors('user');
        expect(User::query()->whereKey($admin->id)->exists())->toBeTrue();
    });

    it('refuses a person with records that keep them, such as marks they entered', function () {
        $admin = manager();
        $owner = GradesFixtures::owner();
        $subject = Subject::factory()->create();
        $student = Student::factory()->create();
        app(SaveMarks::class)->handle($owner, $subject, Section::A, Period::Month1, [$student->uuid => Mark::of(60)]);

        $this->actingAs($admin)->delete("/admin/users/{$owner->id}")->assertSessionHasErrors('user');

        expect(User::query()->whereKey($owner->id)->exists())->toBeTrue()
            ->and($owner->refresh()->hasRole(Role::Administrator))->toBeTrue()
            ->and(AuditEvent::query()->where('action', 'user.deleted')->exists())->toBeFalse();
    });

    it('is for administrators only', function () {
        $admin = manager();
        $user = addedBy($admin);
        $plain = User::factory()->withTwoFactor()->create();

        $this->actingAs($plain)->delete("/admin/users/{$user->id}")->assertForbidden();
        $this->actingAs($plain)->put("/admin/users/{$user->id}", ['name' => 'X', 'email' => 'x@example.test'])->assertForbidden();
    });
});
