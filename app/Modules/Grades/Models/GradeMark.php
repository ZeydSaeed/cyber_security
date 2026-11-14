<?php

namespace App\Modules\Grades\Models;

use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Stage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stored mark (ADR-0034). Written only by SaveMarks, which keeps the
 * window, the teacher's subjects and the audit trail.
 *
 * @property int $id
 * @property int $student_id
 * @property int $subject_id
 * @property Stage $stage
 * @property Period $period
 * @property int|null $mark
 * @property bool $is_absent
 * @property int|null $entered_by_teacher_id
 * @property int|null $entered_by_user_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class GradeMark extends Model
{
    protected $table = 'grade_marks';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => Stage::class,
            'period' => Period::class,
            'mark' => 'integer',
            'is_absent' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function value(): Mark
    {
        return Mark::fromStored($this->mark, $this->is_absent);
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
