<?php

namespace App\Modules\Grades\Models;

use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
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

    /** Whether every section's results are published (PublishResults). */
    public function isPublished(): bool
    {
        return $this->results_published_at !== null;
    }

    /** @var array<string, CarbonImmutable>|null "stage|section" => published at, read once */
    private ?array $sections = null;

    /**
     * The sections published on their own (ADR-0042).
     *
     * @return array<string, CarbonImmutable>
     */
    public function publishedSections(): array
    {
        // None while mark entry is open: publishing needs it closed, and
        // opening it needs every section hidden (ChangeEntryWindow).
        if ($this->isOpen()) {
            return $this->sections = [];
        }

        return $this->sections ??= SectionPublication::query()
            ->where('period', $this->period->value)
            ->get(['stage', 'section', 'published_at'])
            ->mapWithKeys(fn (SectionPublication $row): array => [$row->stage->value.'|'.$row->section->value => $row->published_at])
            ->all();
    }

    /** When one section's results reached its students (null: not yet). */
    public function publishedAtFor(Stage $stage, Section $section): ?CarbonImmutable
    {
        // A section published on its own before the whole period keeps
        // its own moment: publishing everything later must not restart
        // the appeal time of results its students have read since (flow
        // audit, 2026-11-20).
        $own = $this->publishedSections()[$stage->value.'|'.$section->value] ?? null;
        $all = $this->results_published_at;

        return match (true) {
            $all === null => $own,
            $own === null => $all,
            default => $own->min($all),
        };
    }

    /** Whether one section's students may see their results (ADR-0042). */
    public function publishedFor(Stage $stage, Section $section): bool
    {
        return $this->publishedAtFor($stage, $section) !== null;
    }

    /** Whether any section's results are published. */
    public function anyPublished(): bool
    {
        return $this->isPublished() || $this->publishedSections() !== [];
    }
}
