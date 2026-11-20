<?php

namespace App\Modules\Grades\Domain;

/**
 * The department's years of study (ADR-0034). Each has its own subjects;
 * the first is one group, the second and third (added 2026-11-17, owner's
 * request) have sections A and B.
 */
enum Stage: int
{
    case First = 1;
    case Second = 2;
    case Third = 3;

    /**
     * The sections of the stage (owner's decision, 2026-11-15): the first
     * stage is one group, kept as section A and never shown as a section;
     * the second has sections A and B.
     *
     * @return non-empty-list<Section>
     */
    public function sections(): array
    {
        return match ($this) {
            self::First => [Section::A],
            self::Second, self::Third => [Section::A, Section::B],
        };
    }

    public function hasSection(Section $section): bool
    {
        return in_array($section, $this->sections(), true);
    }

    /**
     * Whether the stage is divided into sections at all (worth showing).
     */
    public function isDivided(): bool
    {
        return count($this->sections()) > 1;
    }

    /**
     * The stage by its ordinal, never its number (owner's request,
     * 2026-11-16): "First" or "Second", translated.
     */
    public function name(): string
    {
        return match ($this) {
            self::First => __('First'),
            self::Second => __('Second'),
            self::Third => __('Third'),
        };
    }

    /** "First stage" or "Second stage", translated. */
    public function label(): string
    {
        return match ($this) {
            self::First => __('First stage'),
            self::Second => __('Second stage'),
            self::Third => __('Third stage'),
        };
    }

    /**
     * @return list<int>
     */
    public static function values(): array
    {
        return array_map(fn (self $stage): int => $stage->value, self::cases());
    }
}
