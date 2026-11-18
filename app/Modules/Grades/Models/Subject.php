<?php

namespace App\Modules\Grades\Models;

use App\Modules\Grades\Database\Factories\SubjectFactory;
use App\Modules\Grades\Domain\Stage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A subject of one stage (ADR-0034), with a teacher for each of its
 * stage's sections (SubjectTeacher, owner's request 2026-11-18).
 *
 * @property int $id
 * @property string $uuid
 * @property Stage $stage
 * @property string $name
 * @property int $sort_order
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[UseFactory(SubjectFactory::class)]
class Subject extends Model
{
    /** @use HasFactory<SubjectFactory> */
    use HasFactory;

    use HasUuids;

    public const MORPH = 'grade_subject';

    protected $table = 'grade_subjects';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => Stage::class,
            'sort_order' => 'integer',
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
     * Who teaches each section, one row per section that has a teacher.
     *
     * @return HasMany<SubjectTeacher, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(SubjectTeacher::class, 'subject_id');
    }
}
