<?php

namespace App\Modules\Grades\Application\Import;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The file to fill for the student import (owner's request, 2026-11-16).
 * The first sheet, the one the import reads (SpreadsheetRows), holds only
 * the headers, so nothing in it is imported by mistake; the second explains
 * each column and shows filled-in rows. Headers come translated and are
 * ones StudentImportPlanner recognises.
 */
final class StudentImportTemplate
{
    /**
     * @param  array{sheet: string, help: string, name: string, stage: string, section: string, number: string, column: string, how: string, rules: array{name: string, stage: string, section: string, number: string}, example: string, examples: list<list<string>>, notes: list<string>}  $words
     */
    public function write(string $path, array $words, bool $rightToLeft): void
    {
        $writer = new Writer(new Options);
        $writer->openToFile($path);
        $bold = (new Style)->withFontBold(true);
        $headers = [$words['name'], $words['stage'], $words['section'], $words['number']];

        $students = $writer->getCurrentSheet();
        $students->setName(mb_substr($words['sheet'], 0, 31));
        if ($rightToLeft) {
            $students->setSheetView(new SheetView(rightToLeft: true));
        }
        $writer->addRow(Row::fromValuesWithStyle($headers, $bold));

        $help = $writer->addNewSheetAndMakeItCurrent();
        $help->setName(mb_substr($words['help'], 0, 31));
        if ($rightToLeft) {
            $help->setSheetView(new SheetView(rightToLeft: true));
        }

        $writer->addRow(Row::fromValuesWithStyle([$words['column'], $words['how']], $bold));
        foreach (['name', 'stage', 'section', 'number'] as $column) {
            $writer->addRow(Row::fromValues([$words[$column], $words['rules'][$column]]));
        }

        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValuesWithStyle([$words['example']], $bold));
        $writer->addRow(Row::fromValuesWithStyle($headers, $bold));
        foreach ($words['examples'] as $example) {
            $writer->addRow(Row::fromValues($example));
        }

        $writer->addRow(Row::fromValues([]));
        foreach ($words['notes'] as $note) {
            $writer->addRow(Row::fromValues([$note]));
        }

        $writer->close();
    }
}
