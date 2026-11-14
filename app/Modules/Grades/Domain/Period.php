<?php

namespace App\Modules\Grades\Domain;

/**
 * The marking periods (ADR-0034). Only the first month for now; a later
 * month is a new case, not a new table.
 */
enum Period: string
{
    case Month1 = 'month_1';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $period): string => $period->value, self::cases());
    }
}
