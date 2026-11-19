<?php

namespace App\Modules\Grades\Domain;

use InvalidArgumentException;

/**
 * The lowest percentage of each band (ADR-0039), highest band first and
 * strictly falling: by default 90, 80, 70, 60, 50.
 */
final readonly class GradeBands
{
    public const DEFAULTS = ['excellent' => 90, 'very_good' => 80, 'good' => 70, 'fair' => 60, 'pass' => 50];

    /**
     * @param  array<string, int>  $minimums  band value => lowest percentage
     */
    private function __construct(public array $minimums) {}

    /**
     * @param  array<string, mixed>  $minimums
     */
    public static function of(array $minimums): self
    {
        $clean = [];
        $previous = 101;

        foreach (GradeBand::ranked() as $band) {
            $value = $minimums[$band->value] ?? null;
            if (! is_int($value) || $value < 0 || $value >= $previous) {
                throw new InvalidArgumentException('Each band starts below the one above it, from 100 down to 0.');
            }
            $clean[$band->value] = $value;
            $previous = $value;
        }

        return new self($clean);
    }

    public static function defaults(): self
    {
        return self::of(self::DEFAULTS);
    }

    public function for(float $percent, bool $passed): GradeBand
    {
        if (! $passed) {
            return GradeBand::Fail;
        }

        foreach (GradeBand::ranked() as $band) {
            if ($percent >= $this->minimums[$band->value]) {
                return $band;
            }
        }

        // Passed, yet below the Pass band: the subject's own pass mark is lower.
        return GradeBand::Pass;
    }
}
