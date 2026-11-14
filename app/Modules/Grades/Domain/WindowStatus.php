<?php

namespace App\Modules\Grades\Domain;

/**
 * Whether teachers may write marks for a period (ADR-0034):
 *
 *   open --close--> closed --reopen--> open
 *
 * The owner opens, closes and reopens; teachers write only while it is open.
 */
enum WindowStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    public function canBecome(self $next): bool
    {
        return $this !== $next;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }
}
