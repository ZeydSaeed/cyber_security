<?php

namespace App\Modules\Grades\Models;

use App\Models\User;
use App\Modules\Grades\Domain\EditRequestStatus;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A teacher asking to change one section's locked marks (ADR-0037).
 *
 * @property int $id
 * @property string $uuid
 * @property int $teacher_id
 * @property int $subject_id
 * @property Section $section
 * @property Period $period
 * @property string $reason
 * @property EditRequestStatus $status
 * @property int|null $granted_hours
 * @property int|null $decided_by_user_id
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class GradeEditRequest extends Model
{
    use HasUuids;

    public const MORPH = 'grade_edit_request';

    protected $table = 'grade_edit_requests';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'section' => Section::class,
            'period' => Period::class,
            'status' => EditRequestStatus::class,
            'granted_hours' => 'integer',
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
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
