<?php

namespace App\Modules\Grades\Models;

use App\Modules\Grades\Domain\AppealStatus;
use App\Modules\Grades\Domain\Period;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's appeal of one subject's mark (ADR-0042).
 *
 * @property int $id
 * @property string $uuid
 * @property int $student_id
 * @property int $subject_id
 * @property Period $period
 * @property string $reason
 * @property AppealStatus $status
 * @property string|null $response
 * @property int|null $decided_by_user_id
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class GradeAppeal extends Model
{
    use HasUuids;

    public const MORPH = 'grade_appeal';

    protected $table = 'grade_appeals';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period' => Period::class,
            'status' => AppealStatus::class,
            'decided_at' => 'immutable_datetime',
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
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }
}
