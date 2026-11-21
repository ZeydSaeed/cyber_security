<?php

use App\Access\Role;
use App\Models\User;
use App\Platform\Access\RoleAssignment;
use App\Platform\Audit\AuditEvent;
use App\Platform\Backup\DatabaseExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The database copy (owner's request, 2026-11-21): taken on the server,
| then saved once by the person who took it. pg_dump itself is replaced
| here (the suite runs on SQLite); the real dump was checked by hand.
*/

uses(RefreshDatabase::class);

function backupAdmin(): User
{
    $user = User::factory()->withTwoFactor()->create();
    RoleAssignment::query()->forceCreate(['user_id' => $user->id, 'role' => Role::Administrator->value]);

    return $user->refresh();
}

beforeEach(function () {
    $directory = storage_path('framework/testing/exports');
    File::ensureDirectoryExists($directory);

    $this->app->instance(DatabaseExport::class, new class($directory) extends DatabaseExport
    {
        public function __construct(private string $directory) {}

        public function prepare(): array
        {
            $id = (string) Str::uuid();
            File::put("{$this->directory}/{$id}.sql", "-- PostgreSQL database dump complete\n");

            return ['id' => $id, 'name' => 'cyber_security-test.sql', 'bytes' => 37];
        }

        public function find(string $id): ?string
        {
            $path = "{$this->directory}/{$id}.sql";

            return is_file($path) ? $path : null;
        }
    });
});

it('shows the page to administrators only', function () {
    $this->actingAs(backupAdmin())
        ->get('/admin/backup')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/backup'));

    $this->actingAs(User::factory()->withTwoFactor()->create())
        ->get('/admin/backup')
        ->assertForbidden();
});

it('takes a copy, then saves it once, and audits both', function () {
    $admin = backupAdmin();

    $copy = $this->actingAs($admin)
        ->postJson('/admin/backup', ['password' => 'password'])
        ->assertOk()
        ->assertJsonPath('name', 'cyber_security-test.sql')
        ->json();

    $response = $this->get($copy['url'])->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('cyber_security-test.sql');

    // Sent once: the file is gone and the link no longer works.
    $this->get($copy['url'])->assertNotFound();

    expect(AuditEvent::query()->where('action', 'backup.database_exported')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'backup.database_downloaded')->count())->toBe(1);
});

it('refuses a copy taken in another session', function () {
    $copy = $this->actingAs(backupAdmin())->postJson('/admin/backup', ['password' => 'password'])->json();

    $this->flushSession();

    $this->actingAs(backupAdmin())->get($copy['url'])->assertNotFound();
});

it('refuses people without roles.manage', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create())
        ->postJson('/admin/backup', ['password' => 'password'])
        ->assertForbidden();
});

it('takes no copy without the person\'s own password, and audits a wrong one', function () {
    $admin = backupAdmin();
    $exported = fn () => AuditEvent::query()->where('action', 'backup.database_exported')->count();
    $refused = fn () => AuditEvent::query()->where('action', 'backup.password_refused')->count();

    $this->actingAs($admin)->postJson('/admin/backup')->assertUnprocessable()->assertJsonValidationErrors('password');
    expect($refused())->toBe(0);

    $this->postJson('/admin/backup', ['password' => 'not-my-password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');

    expect($exported())->toBe(0)
        ->and($refused())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'backup.password_refused')->value('actor_id'))->toBe($admin->id);

    // Another administrator's password is not this person's.
    User::factory()->withTwoFactor()->create(['password' => 'other-secret-password']);
    $this->postJson('/admin/backup', ['password' => 'other-secret-password'])->assertUnprocessable();
    expect($exported())->toBe(0);

    $this->postJson('/admin/backup', ['password' => 'password'])->assertOk();
    expect($exported())->toBe(1);
});
