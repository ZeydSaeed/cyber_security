<?php

namespace App\Modules\Grades\Models;

use App\Modules\Grades\Database\Factories\StudentFactory;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A student in one stage and section (ADR-0034), imported by the owner.
 *
 * @property int $id
 * @property string $uuid
 * @property string $full_name
 * @property string $name_key
 * @property Stage $stage
 * @property Section $section
 * @property string|null $student_number
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[UseFactory(StudentFactory::class)]
class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use HasFactory;

    use HasUuids;

    public const MORPH = 'grade_student';

    protected $table = 'grade_students';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => Stage::class,
            'section' => Section::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return HasMany<GradeMark, $this>
     */
    public function marks(): HasMany
    {
        return $this->hasMany(GradeMark::class, 'student_id');
    }
}
