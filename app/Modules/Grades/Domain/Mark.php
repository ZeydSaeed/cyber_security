<?php

namespace App\Modules\Grades\Domain;

use InvalidArgumentException;

/**
 * One student's mark in one subject (ADR-0034): a whole number from 0 to
 * 100, or absent. The table repeats the rule as a CHECK constraint.
 */
final readonly class Mark
{
    public const MIN = 0;

    public const MAX = 100;

    public const ABSENT = 'absent';

    private function __construct(public ?int $value, public bool $absent) {}

    public static function of(int $value): self
    {
        if ($value < self::MIN || $value > self::MAX) {
            throw new InvalidArgumentException('A mark is a whole number from '.self::MIN.' to '.self::MAX.'.');
        }

        return new self($value, false);
    }

    public static function absent(): self
    {
        return new self(null, true);
    }

    /**
     * Reads what a form sends: a whole number from 0 to 100, or "absent".
     * Anything else (empty, 12.5, 101, "abc") is null: not a mark.
     */
    public static function fromInput(mixed $input): ?self
    {
        if ($input === self::ABSENT) {
            return self::absent();
        }

        if (is_int($input)) {
            return $input >= self::MIN && $input <= self::MAX ? self::of($input) : null;
        }

        if (is_string($input) && preg_match('/^\s*(\d{1,3})\s*$/', $input, $match) === 1) {
            return self::fromInput((int) $match[1]);
        }

        return null;
    }

    /**
     * Rebuilds a stored mark (the table guarantees the combination).
     */
    public static function fromStored(?int $value, bool $absent): self
    {
        return $absent ? self::absent() : self::of((int) $value);
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
