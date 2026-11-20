<?php

namespace App\Modules\Grades\Models;

use App\Modules\Grades\Database\Factories\SubjectFactory;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\SubjectScale;
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
 * @property int $max_mark
 * @property int|null $pass_mark
 * @property int|null $exempt_mark
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
            'max_mark' => 'integer',
            'pass_mark' => 'integer',
            'exempt_mark' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * How the subject is marked (ADR-0039): its maximum, and its own pass
     * and exemption marks or the department's percentages of the maximum.
     */
    public function scale(GradeSettings $settings): SubjectScale
    {
        return SubjectScale::from($this->max_mark, $this->pass_mark, $this->exempt_mark, $settings->pass_percent, $settings->exempt_subject_percent);
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
