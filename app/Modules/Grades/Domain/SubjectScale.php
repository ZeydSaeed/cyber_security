<?php

namespace App\Modules\Grades\Domain;

use InvalidArgumentException;

/**
 * How one subject is marked (ADR-0039): out of `max` (100 unless the owner
 * says 80, 50 or any other), passed at `pass`, and, when set, exempt at
 * `exempt` (the individual exemption).
 */
final readonly class SubjectScale
{
    public function __construct(public int $max, public int $pass, public ?int $exempt = null)
    {
        if ($max < 1 || $max > Mark::CEILING || $pass < 0 || $pass > $max || ($exempt !== null && ($exempt < $pass || $exempt > $max))) {
            throw new InvalidArgumentException('A subject is out of 1 to '.Mark::CEILING.', passed at or below its maximum, exempt at or above its pass mark.');
        }
    }

    /**
     * The pass and exemption marks of a subject: its own, else the
     * department's percentages of its maximum (rounded up).
     */
    public static function from(int $max, ?int $pass, ?int $exempt, int $passPercent, ?int $exemptPercent): self
    {
        $pass ??= (int) ceil($max * $passPercent / 100);
        $exempt ??= $exemptPercent === null ? null : (int) ceil($max * $exemptPercent / 100);

        return new self($max, $pass, $exempt === null ? null : max($exempt, $pass));
    }

    public function passes(Mark $mark): bool
    {
        return $mark->passes($this->pass);
    }

    public function exempts(Mark $mark): bool
    {
        return $this->exempt !== null && $mark->passes($this->exempt);
    }

    public function percent(Mark $mark): float
    {
        return round($mark->points() * 100 / $this->max, 2);
    }
}
