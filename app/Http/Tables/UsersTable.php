<?php

namespace App\Http\Tables;

use App\Access\Role;
use App\Models\User;
use App\Platform\Tables\TableDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * The user list for administrators (ADR-0026).
 *
 * @extends TableDefinition<User>
 */
class UsersTable extends TableDefinition
{
    public function name(): string
    {
        return 'users';
    }

    public function columns(): array
    {
        return [
            'name' => ['sortable' => true, 'hideable' => false],
            'email' => ['sortable' => true, 'hideable' => true],
            // The eye that shows the password an administrator set.
            'password' => ['sortable' => false, 'hideable' => true],
        ];
    }

    public function filters(): array
    {
        return [
            'role' => [...array_map(fn (Role $role): string => $role->value, Role::cases()), 'none'],
        ];
    }

    public function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    public function applySearch(Builder $query, string $search): void
    {
        $this->whereContains($query, ['name', 'email'], $search);
    }

    public function applyFilter(Builder $query, string $filter, string $value): void
    {
        match ($filter) {
            'role' => $value === 'none'
                ? $query->whereDoesntHave('roleAssignments')
                : $query->whereHas('roleAssignments', fn (Builder $assignment) => $assignment->where('role', $value)),
            default => null,
        };
    }
}
