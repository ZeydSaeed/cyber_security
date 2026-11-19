<?php

namespace App\Modules\Grades\Domain;

use InvalidArgumentException;

/**
 * One student's mark in one subject (ADR-0034): a whole number from 0 to
 * the subject's maximum (100 unless the owner sets another, at most
 * CEILING; ADR-0039), or absent. The table repeats the ceiling as a CHECK
 * constraint; SaveMarks checks the subject's own maximum.
 */
final readonly class Mark
{
    public const MIN = 0;

    /** The usual maximum of a subject. */
    public const MAX = 100;

    /** The highest maximum a subject may have. */
    public const CEILING = 1000;

    public const ABSENT = 'absent';

    private function __construct(public ?int $value, public bool $absent) {}

    public static function of(int $value, int $max = self::MAX): self
    {
        if ($value < self::MIN || $value > $max || $max > self::CEILING) {
            throw new InvalidArgumentException('A mark is a whole number from '.self::MIN.' to '.$max.'.');
        }

        return new self($value, false);
    }

    public static function absent(): self
    {
        return new self(null, true);
    }

    /**
     * Reads what a form sends: a whole number from 0 to the subject's
     * maximum, or "absent". Anything else (empty, 12.5, above the maximum,
     * "abc") is null: not a mark.
     */
    public static function fromInput(mixed $input, int $max = self::MAX): ?self
    {
        if ($input === self::ABSENT) {
            return self::absent();
        }

        if (is_int($input)) {
            return $input >= self::MIN && $input <= $max && $max <= self::CEILING ? self::of($input, $max) : null;
        }

        if (is_string($input) && preg_match('/^\s*(\d{1,4})\s*$/', $input, $match) === 1) {
            return self::fromInput((int) $match[1], $max);
        }

        return null;
    }

    /**
     * Rebuilds a stored mark (the table guarantees the combination).
     */
    public static function fromStored(?int $value, bool $absent): self
    {
        return $absent ? self::absent() : self::of((int) $value, self::CEILING);
    }

    /**
     * What counts in the total: an absent subject counts as 0.
     */
    public function points(): int
    {
        return $this->value ?? 0;
    }

    public function passes(int $passMark): bool
    {
        return ! $this->absent && (int) $this->value >= $passMark;
    }

    public function equals(?self $other): bool
    {
        return $other !== null && $other->value === $this->value && $other->absent === $this->absent;
    }

    /**
     * For audit records and pages: the number, or "absent".
     */
    public function toScalar(): int|string
    {
        return $this->absent ? self::ABSENT : (int) $this->value;
    }
}
