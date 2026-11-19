<?php

namespace App\Modules\Grades\Models;

use App\Modules\Grades\Domain\GradeBands;
use App\Modules\Grades\Domain\ResultCalculator;
use App\Modules\Grades\Domain\ResultDisplay;
use App\Modules\Grades\Domain\StudentAccess;
use App\Modules\Grades\Domain\TeacherAccess;
use App\Modules\Grades\Domain\TeacherResults;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

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
 * @property int $pass_percent
 * @property int|null $exempt_subject_percent
 * @property int|null $exempt_overall_percent
 * @property array<string, mixed>|null $grade_bands
 * @property array<string, mixed>|null $result_options
 * @property array<string, mixed>|null $mark_tones
 * @property TeacherResults $teacher_results
 * @property int|null $appeal_days
 * @property int|null $absence_flag
 * @property array<string, mixed>|null $teacher_view
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
            'pass_percent' => 'integer',
            'exempt_subject_percent' => 'integer',
            'exempt_overall_percent' => 'integer',
            'grade_bands' => 'array',
            'result_options' => 'array',
            'mark_tones' => 'array',
            'teacher_results' => TeacherResults::class,
            'appeal_days' => 'integer',
            'absence_flag' => 'integer',
            'teacher_view' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** The grade bands (ADR-0039): the saved ones, else the defaults. */
    public function bands(): GradeBands
    {
        try {
            return $this->grade_bands === null ? GradeBands::defaults() : GradeBands::of($this->grade_bands);
        } catch (InvalidArgumentException) {
            return GradeBands::defaults();
        }
    }

    /**
     * What a result card shows (ADR-0039).
     *
     * @return array<string, bool>
     */
    public function options(): array
    {
        return ResultDisplay::options($this->result_options);
    }

    /**
     * The status tones of marks (ADR-0039).
     *
     * @return array<string, string>
     */
    public function tones(): array
    {
        return ResultDisplay::tones($this->mark_tones);
    }

    /**
     * What teachers see of their students' results (ADR-0040).
     *
     * @return array<string, bool>
     */
    public function teacherView(): array
    {
        return ResultDisplay::teacherOptions($this->teacher_view);
    }

    public function calculator(): ResultCalculator
    {
        return new ResultCalculator($this->bands(), $this->exempt_overall_percent);
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
