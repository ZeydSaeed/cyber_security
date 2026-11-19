<?php

namespace App\Modules\Grades\Models;

use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\WindowStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A marking period and whether teachers may write in it (ADR-0034).
 *
 * @property int $id
 * @property Period $period
 * @property WindowStatus $status
 * @property CarbonImmutable|null $opened_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $results_published_at
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class GradePeriod extends Model
{
    public const MORPH = 'grade_period';

    protected $table = 'grade_periods';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period' => Period::class,
            'status' => WindowStatus::class,
            'lock_version' => 'integer',
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'results_published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * The period's row, made (closed, never opened) the first time it is
     * needed, so every period always has one. Insert-or-ignore: two first
     * uses at the same moment make one row, and neither fails.
     */
    public static function for(Period $period): self
    {
        // One query once the row exists (it is read on every teacher's page).
        $row = self::query()->where('period', $period->value)->first();
        if ($row !== null) {
            return $row;
        }

        $now = now();
        self::query()->insertOrIgnore([
            'period' => $period->value,
            'status' => WindowStatus::Closed->value,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return self::query()->where('period', $period->value)->firstOrFail();
    }

    public function isOpen(): bool
    {
        return $this->status === WindowStatus::Open;
    }

    /** Whether students may see the results (PublishResults). */
    public function isPublished(): bool
    {
        return $this->results_published_at !== null;
    }
}
