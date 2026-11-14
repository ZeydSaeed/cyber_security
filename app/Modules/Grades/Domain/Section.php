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

    /**
     * Reads a section as people write it: "A", "a", "section A", or the
     * Arabic letters alef and beh, with or without the word for "section".
     */
    public static function fromText(string|int|float|null $text): ?self
    {
        $value = NameKey::of((string) $text);
        $value = trim((string) preg_replace('/^(الشعبه|شعبه|section)\s*/u', '', $value));

        return match ($value) {
            'a', 'ا' => self::A,
            'b', 'ب' => self::B,
            default => null,
        };
    }
}
