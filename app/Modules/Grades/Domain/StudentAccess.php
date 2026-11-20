<?php

namespace App\Modules\Grades\Domain;

/**
 * Whether students may sign in at all (ADR-0037); a single student can be
 * stopped on their own as well.
 */
enum StudentAccess: string
{
    case Allowed = 'allowed';
    case Blocked = 'blocked';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $access): string => $access->value, self::cases());
    }
}
