<?php

namespace App\Modules\Grades\Domain;

/**
 * What a teacher may do (ADR-0037). Set department-wide, per teacher, or
 * per section of a subject; the narrower setting wins.
 *
 * - Edit: write marks while mark entry is open.
 * - Timed: the same, but each mark can be changed only for a number of
 *   hours after it was first entered; then it is locked.
 * - ViewOnly: sign in and read the marks, change nothing.
 * - Blocked: on a teacher, no signing in; on a section, the section is
 *   hidden from the teacher.
 */
enum TeacherAccess: string
{
    case Edit = 'edit';
    case Timed = 'timed';
    case ViewOnly = 'view_only';
    case Blocked = 'blocked';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $mode): string => $mode->value, self::cases());
    }
}
