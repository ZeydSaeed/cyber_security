<?php

namespace App\Modules\Grades\Domain;

/**
 * The two sections of every stage (ADR-0034).
 */
enum Section: string
{
    case A = 'A';
    case B = 'B';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $section): string => $section->value, self::cases());
    }
}
