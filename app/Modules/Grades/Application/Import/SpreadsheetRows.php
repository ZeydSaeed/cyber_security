<?php

namespace App\Modules\Grades\Application\Import;

use App\Platform\Exceptions\DomainRuleViolation;
use DateTimeInterface;
use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Reads the first sheet of an .xlsx or .csv file as rows of text
 * (ADR-0034), streaming, with the spreadsheet's own row numbers (empty rows
 * kept, so "row 12" is row 12 in Excel). A CSV may be separated by commas or
 * semicolons (Excel writes semicolons in some locales), and may start with a
 * byte order mark.
 */
final class SpreadsheetRows
{
    /**
     * @return list<array{row: int, cells: list<string>}>
     */
    public function read(string $path, string $extension, int $maxRows): array
    {
        $reader = $this->reader($path, strtolower($extension));
        $rows = [];

        try {
            $reader->open($path);

            foreach ($reader->getSheetIterator() as $sheet) {
                $number = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $number++;
                    $cells = array_values(array_map(fn (mixed $cell): string => $this->text($cell), $row->toArray()));

                    if (implode('', $cells) === '') {
                        continue;
                    }

                    if (count($rows) >= $maxRows) {
                        throw new DomainRuleViolation(__('The file has more than :max rows.', ['max' => $maxRows]), 'file');
                    }

                    $rows[] = ['row' => $number, 'cells' => $cells];
                }

                break; // The first sheet only.
            }
        } catch (OpenSpoutException) {
            throw new DomainRuleViolation(__('The file could not be read as a spreadsheet.'), 'file');
        } finally {
            $reader->close();
        }

        return $rows;
    }

    private function reader(string $path, string $extension): XlsxReader|CsvReader
    {
        return match ($extension) {
            'xlsx' => new XlsxReader(new XlsxOptions(SHOULD_PRESERVE_EMPTY_ROWS: true)),
            'csv' => new CsvReader(new CsvOptions(SHOULD_PRESERVE_EMPTY_ROWS: true, FIELD_DELIMITER: $this->delimiter($path))),
            default => throw new DomainRuleViolation(__('Upload an Excel (.xlsx) or CSV file.'), 'file'),
        };
    }

    private function delimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        $first = $handle === false ? '' : (string) fgets($handle);
        if ($handle !== false) {
            fclose($handle);
        }

        return substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
    }

    private function text(mixed $cell): string
    {
        $text = match (true) {
            $cell instanceof DateTimeInterface => $cell->format('Y-m-d'),
            is_float($cell) && floor($cell) === $cell => (string) (int) $cell,
            is_scalar($cell) => (string) $cell,
            default => '',
        };

        // A byte order mark at the start of a CSV, and stray spaces.
        return trim(str_replace("\u{FEFF}", '', $text));
    }
}
