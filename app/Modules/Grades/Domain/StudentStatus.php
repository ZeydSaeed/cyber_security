<?php

namespace App\Modules\Grades\Domain;

/**
 * How a student is enrolled, when it is not the usual way (owner's
 * request, 2026-11-16): "external" is the Arabic intisab, a student
 * enrolled by correspondence. A regular student has no status (null).
 */
enum StudentStatus: string
{
    case External = 'external';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }
}
