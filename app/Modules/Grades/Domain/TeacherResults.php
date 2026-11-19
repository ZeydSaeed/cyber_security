<?php

namespace App\Modules\Grades\Domain;

/**
 * When teachers may read their own students' results (ADR-0040): never,
 * once the owner has published them, or always (while marks come in too).
 */
enum TeacherResults: string
{
    case Off = 'off';
    case Published = 'published';
    case Always = 'always';

    public function allows(bool $published): bool
    {
        return match ($this) {
            self::Off => false,
            self::Published => $published,
            self::Always => true,
        };
    }
}
