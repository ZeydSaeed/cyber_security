<?php

namespace App\Modules\Grades\Models;

use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One section's results published before the others (ADR-0042).
 * Publishing all at once is GradePeriod::$results_published_at.
 *
 * @property int $id
 * @property Stage $stage
 * @property Section $section
 * @property Period $period
 * @property CarbonImmutable $published_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class SectionPublication extends Model
{
    protected $table = 'grade_section_publications';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => Stage::class,
            'section' => Section::class,
            'period' => Period::class,
            'published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
