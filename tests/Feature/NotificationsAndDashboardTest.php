<?php

use App\Access\Role;
use App\Models\User;
use App\Notifications\RoleChanged;
use App\Platform\Access\RoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| Notifications (#8), ADR-0029. The dashboard and reports pages were
| removed by the owner (2026-11-15; DashboardTest checks their addresses
| lead to /admin), so their tests went with them.
*/

uses(RefreshDatabase::class);

function personWithRole(?Role $role = null): User
{
    $user = User::factory()->withTwoFactor()->create();

    if ($role) {
        RoleAssignment::query()->forceCreate(['user_id' => $user->id, 'role' => $role->value]);
    }

    return $user->refresh();
}

describe('notifications', function () {
    it('tells a person when a role really changes, and only then', function () {
        $admin = personWithRole(Role::Administrator);
        $person = personWithRole();

        $this->actingAs($admin)->post('/admin/users/roles', ['action' => 'assign', 'role' => 'auditor', 'ids' => [$person->id]]);
        // Already held: no change, no news.
        $this->actingAs($admin)->post('/admin/users/roles', ['action' => 'assign', 'role' => 'auditor', 'ids' => [$person->id]]);
        $this->actingAs($admin)->post('/admin/users/roles', ['action' => 'revoke', 'role' => 'auditor', 'ids' => [$person->id]]);

        $kinds = $person->notifications()->reorder()->oldest()->get()->map(fn ($n) => $n->data['kind'])->all();
        expect($kinds)->toBe(['role_granted', 'role_removed'])
            ->and($person->notifications()->first()->type)->toBe(RoleChanged::class);
    });

    it('counts and marks read for their owner only', function () {
        $person = personWithRole();
        $other = personWithRole();
        $person->notify(new RoleChanged('role_granted', 'auditor', 'Admin'));
        $person->notify(new RoleChanged('role_removed', 'auditor', 'Admin'));
        $id = $person->notifications()->first()->id;

        expect($person->unreadNotifications()->count())->toBe(2);

        $this->actingAs($other)->post("/notifications/{$id}/read")->assertNotFound();
        $this->actingAs($person)->post("/notifications/{$id}/read")->assertRedirect();
        expect($person->unreadNotifications()->count())->toBe(1);

        $this->actingAs($person)->post('/notifications/read-all')->assertRedirect();
        expect($person->unreadNotifications()->count())->toBe(0);
    });
});
