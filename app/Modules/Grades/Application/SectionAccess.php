<?php

namespace App\Modules\Grades\Application;

use App\Modules\Grades\Domain\EditPolicy;
use App\Modules\Grades\Domain\TeacherAccess;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\GradeSettings;
use App\Modules\Grades\Models\SubjectTeacher;
use App\Modules\Grades\Models\Teacher;

/**
 * What a teacher may do in one section they teach (ADR-0037). The
 * narrowest setting wins: the section's mode, else the teacher's, else the
 * department's. The editing hours are the teacher's, else the
 * department's; a grant is the section's.
 */
final class SectionAccess
{
    public static function mode(Teacher $teacher, SubjectTeacher $row, GradeSettings $settings): TeacherAccess
    {
        return $row->access_mode ?? $teacher->accessMode($settings);
    }

    /** A blocked section is hidden from its teacher, as if not theirs. */
    public static function hidden(Teacher $teacher, SubjectTeacher $row, GradeSettings $settings): bool
    {
        return self::mode($teacher, $row, $settings) === TeacherAccess::Blocked;
    }

    /**
     * @param  bool|null  $published  whether this section's results are published (ADR-0042); null: the whole period's
     */
    public static function policy(Teacher $teacher, SubjectTeacher $row, GradeSettings $settings, GradePeriod $period, bool $approved = false, ?bool $published = null): EditPolicy
    {
        return new EditPolicy(
            approved: $approved,
            mode: self::mode($teacher, $row, $settings),
            editHours: $teacher->edit_hours ?? $settings->teacher_edit_hours,
            grantUntil: $row->edit_until,
            windowOpen: $period->isOpen(),
            published: $published ?? $period->isPublished(),
            now: now(),
        );
    }
}
