<?php

namespace App\Modules\Grades\Models;

use App\Modules\Grades\Domain\Behaviour;
use App\Modules\Grades\Domain\Period;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A student's behaviour and the owner's notes for a period (ADR-0039).
 *
 * @property int $id
 * @property int $student_id
 * @property Period $period
 * @property Behaviour|null $behaviour
 * @property string|null $notes
 * @property int|null $updated_by_user_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class GradeStudentNote extends Model
{
    protected $table = 'grade_student_notes';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period' => Period::class,
            'behaviour' => Behaviour::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
