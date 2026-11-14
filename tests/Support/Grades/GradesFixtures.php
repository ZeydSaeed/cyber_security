<?php

namespace Tests\Support\Grades;

use App\Access\Role;
use App\Models\User;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\GradePeriod;
use App\Platform\Access\RoleAssignment;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Shared set-up for the Grades tests (ADR-0034).
 */
final class GradesFixtures
{
    public static function owner(): User
    {
        $user = User::factory()->withTwoFactor()->create();
        RoleAssignment::query()->forceCreate(['user_id' => $user->id, 'role' => Role::Administrator->value]);

        return $user;
    }

    public static function openWindow(Period $period = Period::Month1): GradePeriod
    {
        $row = GradePeriod::for($period);
        $row->forceFill(['status' => WindowStatus::Open, 'opened_at' => now(), 'closed_at' => null])->save();

        return $row;
    }

    /**
     * Writes rows to a temporary .csv or .xlsx file and returns its path.
     *
     * @param  list<list<string|int>>  $rows
     */
    public static function spreadsheet(array $rows, string $extension = 'csv', string $delimiter = ','): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'grades-'.bin2hex(random_bytes(6)).'.'.$extension;

        if ($extension === 'xlsx') {
            $writer = new Writer;
            $writer->openToFile($path);
            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }
            $writer->close();

            return $path;
        }

        $lines = array_map(fn (array $row): string => implode($delimiter, array_map(
            fn (string|int $cell): string => str_contains((string) $cell, $delimiter) ? '"'.$cell.'"' : (string) $cell,
            $row,
        )), $rows);
        file_put_contents($path, implode("\r\n", $lines));

        return $path;
    }
}
