<?php

namespace App\Modules\Grades\Http;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * A length of time entered as days, hours and minutes, and a moment
 * entered as a date and time (ADR-0043). The page sends the three numbers
 * as `<prefix>_days`, `<prefix>_hours` and `<prefix>_minutes`, and the
 * moment as an ISO 8601 string with its offset (the reader's local time).
 */
final class DurationInput
{
    /**
     * Validation rules for the three numbers.
     *
     * @return array<string, list<string>>
     */
    public static function rules(string $prefix): array
    {
        return [
            "{$prefix}_days" => ['nullable', 'integer', 'min:0', 'max:365'],
            "{$prefix}_hours" => ['nullable', 'integer', 'min:0', 'max:8760'],
            "{$prefix}_minutes" => ['nullable', 'integer', 'min:0', 'max:525600'],
        ];
    }

    /** The total in minutes; null when every field is empty or zero. */
    public static function minutes(Request $request, string $prefix): ?int
    {
        $total = $request->integer("{$prefix}_days") * 1440
            + $request->integer("{$prefix}_hours") * 60
            + $request->integer("{$prefix}_minutes");

        return $total > 0 ? $total : null;
    }

    public static function moment(Request $request, string $field): ?CarbonImmutable
    {
        return $request->filled($field) ? CarbonImmutable::parse((string) $request->string($field))->utc() : null;
    }
}
