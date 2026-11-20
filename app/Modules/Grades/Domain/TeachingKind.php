<?php

namespace App\Modules\Grades\Domain;

/**
 * How a section of a subject is taught (owner's request, 2026-11-18):
 * theoretical, practical, or both (owner's request, 2026-11-20).
 */
enum TeachingKind: string
{
    case Theory = 'theory';
    case Practical = 'practical';
    case Both = 'both';

    /** "Theoretical" or "Practical", translated. */
    public function label(): string
    {
        return match ($this) {
            self::Theory => __('Theoretical'),
            self::Practical => __('Practical'),
            self::Both => __('Theoretical and practical'),
        };
    }
}
