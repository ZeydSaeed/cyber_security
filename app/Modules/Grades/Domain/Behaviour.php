<?php

namespace App\Modules\Grades\Domain;

/**
 * A student's conduct as the owner records it (ADR-0039).
 */
enum Behaviour: string
{
    case Excellent = 'excellent';
    case VeryGood = 'very_good';
    case Good = 'good';
    case Acceptable = 'acceptable';
    case Poor = 'poor';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $behaviour): string => $behaviour->value, self::cases());
    }
}
