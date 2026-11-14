<?php

namespace App\Modules\Grades\Http;

use App\Access\Permission;
use App\Modules\Grades\Domain\NameKey;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Models\Student;
use App\Platform\Tables\TableDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * The owner's student list (ADR-0026, ADR-0034): server-side search by name
 * (spelling differences ignored, as the import does) or number, filters by
 * stage and section, sorting and paging.
 *
 * @extends TableDefinition<Student>
 */
class StudentsTable extends TableDefinition
{
    public const NAME = 'grades-students';

    public const ABILITY = Permission::GradesViewResults->value;

    public function name(): string
    {
        return self::NAME;
    }

    public function columns(): array
    {
        return [
            'full_name' => ['sortable' => true, 'hideable' => false],
            'student_number' => ['sortable' => true, 'hideable' => true],
            'stage' => ['sortable' => true, 'hideable' => true],
            'section' => ['sortable' => true, 'hideable' => true],
        ];
    }

    public function filters(): array
    {
        return [
            'stage' => array_map('strval', Stage::values()),
            'section' => Section::values(),
        ];
    }

    public function defaultSort(): array
    {
        return ['full_name', 'asc'];
    }

    public function defaultPerPage(): int
    {
        return 50;
    }

    public function applySearch(Builder $query, string $search): void
    {
        $key = NameKey::of($search);

        $query->where(function (Builder $where) use ($key, $search): void {
            $this->whereContains($where, ['name_key'], $key);
            $where->orWhere('student_number', trim($search));
        });
    }

    public function applyFilter(Builder $query, string $filter, string $value): void
    {
        match ($filter) {
            'stage' => $query->where('stage', (int) $value),
            'section' => $query->where('section', $value),
            default => null,
        };
    }
}
