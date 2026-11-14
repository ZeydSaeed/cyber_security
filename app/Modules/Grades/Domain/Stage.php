<?php

namespace App\Modules\Grades\Domain;

/**
 * The department's two years of study (ADR-0034). Each has its own eight
 * subjects and two sections.
 */
enum Stage: int
{
    case First = 1;
    case Second = 2;

    /**
     * @return list<int>
     */
    public static function values(): array
    {
        return array_map(fn (self $stage): int => $stage->value, self::cases());
    }

    /**
     * Reads a stage as people write it in a spreadsheet: 1, 2, "first",
     * "second", or the Arabic words for first and second, with or without
     * the word for "year".
     */
    public static function fromText(string|int|float|null $text): ?self
    {
        $value = NameKey::of((string) $text);
        $value = trim((string) preg_replace('/^(الصف|صف|المرحله|مرحله|stage|year|grade)\s*/u', '', $value));

        return match ($value) {
            '1', 'first', 'الاول', 'اول', 'الاولي', 'اولي' => self::First,
            '2', 'second', 'الثاني', 'ثاني', 'الثانيه', 'ثانيه' => self::Second,
            default => null,
        };
    }
}
