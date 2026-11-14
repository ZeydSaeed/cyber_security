<?php

namespace App\Modules\Grades\Models;

use App\Modules\Grades\Database\Factories\SubjectFactory;
use App\Modules\Grades\Domain\Stage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A subject of one stage, taught by one teacher (ADR-0034).
 *
 * @property int $id
 * @property string $uuid
 * @property Stage $stage
 * @property string $name
 * @property int|null $teacher_id
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
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }
}
