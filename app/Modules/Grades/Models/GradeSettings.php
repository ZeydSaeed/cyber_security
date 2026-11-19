<?php

namespace App\Modules\Grades\Models;

use App\Modules\Grades\Domain\StudentAccess;
use App\Modules\Grades\Domain\TeacherAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The department-wide access rules (ADR-0037), one row.
 *
 * @property int $id
 * @property int $teacher_attempts_per_minute
 * @property int $teacher_attempts_per_hour
 * @property int $student_attempts_per_minute
 * @property int $student_attempts_per_hour
 * @property int|null $teacher_max_logins
 * @property int|null $student_max_logins
 * @property TeacherAccess $teacher_mode
 * @property int|null $teacher_edit_hours
 * @property StudentAccess $student_access
 * @property int $remember_days
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class GradeSettings extends Model
{
    public const MORPH = 'grade_settings';

    /** The one row's id. */
    public const ROW = 1;

    protected $table = 'grade_settings';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'teacher_attempts_per_minute' => 'integer',
            'teacher_attempts_per_hour' => 'integer',
            'student_attempts_per_minute' => 'integer',
            'student_attempts_per_hour' => 'integer',
            'teacher_max_logins' => 'integer',
            'student_max_logins' => 'integer',
            'teacher_mode' => TeacherAccess::class,
            'teacher_edit_hours' => 'integer',
            'student_access' => StudentAccess::class,
            'remember_days' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * The row, made with the defaults the first time it is needed
     * (insert-or-ignore, as GradePeriod::for). Read on every teacher's and
     * student's request: one query once the row exists.
     */
    public static function current(): self
    {
        $row = self::query()->find(self::ROW);
        if ($row !== null) {
            return $row;
        }

        $now = now();
        self::query()->insertOrIgnore(['id' => self::ROW, 'created_at' => $now, 'updated_at' => $now]);

        return self::query()->findOrFail(self::ROW);
    }
}
