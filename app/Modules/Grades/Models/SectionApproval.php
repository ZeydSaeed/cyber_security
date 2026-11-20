<?php

namespace App\Modules\Grades\Models;

use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A teacher's approval of one section's marks in one subject (ADR-0041).
 *
 * @property int $id
 * @property int $subject_id
 * @property Section $section
 * @property Period $period
 * @property int|null $teacher_id
 * @property CarbonImmutable $approved_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class SectionApproval extends Model
{
    protected $table = 'grade_section_approvals';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'section' => Section::class,
            'period' => Period::class,
            'approved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    public static function find(Subject $subject, Section $section, Period $period): ?self
    {
        return self::query()
            ->where('subject_id', $subject->id)
            ->where('section', $section->value)
            ->where('period', $period->value)
            ->first();
    }
}
