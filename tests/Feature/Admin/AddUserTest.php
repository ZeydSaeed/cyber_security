<?php

use App\Access\Role;
use App\Models\User;
use App\Platform\Access\RoleAssignment;
use App\Platform\Audit\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
| Adding a person from the user list (owner's request, 2026-11-18; replaces
| invitations): administrators only; name, address, a password the
| administrator chooses and an optional role; the password is stored
| hashed, never in the audit trail, and no email is sent.
*/

uses(RefreshDatabase::class);

const ADDED_PASSWORD = 'Str0ng!Passw0rd-x';

function adder(): User
{
    $user = User::factory()->withTwoFactor()->create(['name' => 'Admin']);
    RoleAssignment::query()->forceCreate(['user_id' => $user->id, 'role' => Role::Administrator->value]);

    return $user->refresh();
}

it('is for administrators only', function () {
    $user = User::factory()->withTwoFactor()->create();
    RoleAssignment::query()->forceCreate(['user_id' => $user->id, 'role' => Role::Auditor->value]);

    $this->actingAs($user->refresh())
        ->post('/admin/users', ['name' => 'X', 'email' => 'x@example.test', 'password' => ADDED_PASSWORD])
        ->assertForbidden();

    expect(User::query()->where('email', 'x@example.test')->exists())->toBeFalse();
});

it('no longer offers invitations', function () {
    $this->actingAs(adder())->get('/admin/users/invite')->assertNotFound();
});

it('adds a person with a password and a role', function () {
    Notification::fake();
    $admin = adder();

    $this->actingAs($admin)
        ->post('/admin/users', ['name' => 'Huda', 'email' => ' Huda@Example.test ', 'password' => ADDED_PASSWORD, 'roles' => ['auditor']])
        ->assertRedirect('/admin/users');

    $user = User::query()->where('email', 'huda@example.test')->sole();

    expect($user->name)->toBe('Huda')
        ->and(Hash::check(ADDED_PASSWORD, $user->password))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->hasRole(Role::Auditor))->toBeTrue();

    $event = AuditEvent::query()->where('action', 'user.created')->where('subject_id', (string) $user->id)->sole();
    expect(json_encode($event->toArray()))->not->toContain(ADDED_PASSWORD);

    Notification::assertNothingSent();
});

it('adds a person without a role', function () {
    $this->actingAs(adder())
        ->post('/admin/users', ['name' => 'Omar', 'email' => 'omar@example.test', 'password' => ADDED_PASSWORD, 'roles' => ['']])
        ->assertRedirect('/admin/users');

    $user = User::query()->where('email', 'omar@example.test')->sole();
    expect($user->roleAssignments()->exists())->toBeFalse();
});

it('lets the added person sign in with that password', function () {
    $this->actingAs(adder())
        ->post('/admin/users', ['name' => 'Sara', 'email' => 'sara@example.test', 'password' => ADDED_PASSWORD]);

    auth()->logout();

    $this->post('/login', ['email' => 'sara@example.test', 'password' => ADDED_PASSWORD]);

    expect(auth()->user()?->email)->toBe('sara@example.test');
});

it('refuses a taken address, a weak password and an unknown role', function () {
    $admin = adder();
    User::factory()->create(['email' => 'taken@example.test']);

    $this->actingAs($admin)
        ->post('/admin/users', ['name' => 'New', 'email' => 'TAKEN@example.test', 'password' => 'short', 'roles' => ['superuser']])
        ->assertSessionHasErrors(['email', 'password', 'roles.0']);

    $this->actingAs($admin)
        ->post('/admin/users', [])
        ->assertSessionHasErrors(['name', 'email', 'password']);
});
