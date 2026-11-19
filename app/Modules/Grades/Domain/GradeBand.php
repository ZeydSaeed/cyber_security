<?php

namespace App\Modules\Grades\Domain;

/**
 * The word a percentage earns (ADR-0039), from the highest band down. A
 * mark below its subject's pass mark is Fail whatever its percentage.
 */
enum GradeBand: string
{
    case Excellent = 'excellent';
    case VeryGood = 'very_good';
    case Good = 'good';
    case Fair = 'fair';
    case Pass = 'pass';
    case Fail = 'fail';

    /**
     * The bands with a minimum, highest first (Fail has none).
     *
     * @return list<self>
     */
    public static function ranked(): array
    {
        return [self::Excellent, self::VeryGood, self::Good, self::Fair, self::Pass];
    }
}
