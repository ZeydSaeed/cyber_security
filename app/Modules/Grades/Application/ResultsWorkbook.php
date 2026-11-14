<?php

namespace App\Modules\Grades\Application;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * A section's results as an Excel file (ADR-0034): one row per student, a
 * column per subject, then total, percentage, subjects below the pass mark
 * and absences. Headers come translated; marks stay numbers so Excel can
 * sort and sum them, and an absence is the translated word.
 */
final class ResultsWorkbook
{
    /**
     * @param  array{subjects: list<array{uuid: string, name: string}>, students: list<array{name: string, number: string|null, marks: list<int|string|null>, total: int, maximum: int, percentage: float, below: int, absences: int, complete: bool}>}  $sheet
     * @param  array{number: string, name: string, total: string, percentage: string, below: string, absences: string, absent: string, incomplete: string, title: string}  $words
     */
    public function write(string $path, array $sheet, array $words, bool $rightToLeft): void
    {
        $options = new Options;
        $writer = new Writer($options);
        $writer->openToFile($path);
        $sheetObject = $writer->getCurrentSheet();
        $sheetObject->setName(mb_substr($words['title'], 0, 31));
        if ($rightToLeft) {
            $sheetObject->setSheetView(new SheetView(rightToLeft: true));
        }

        $bold = (new Style)->withFontBold(true);
        $writer->addRow(Row::fromValuesWithStyle([
            $words['number'],
            $words['name'],
            ...array_map(fn (array $subject): string => $subject['name'], $sheet['subjects']),
            $words['total'],
            $words['percentage'],
            $words['below'],
            $words['absences'],
        ], $bold));

        foreach ($sheet['students'] as $student) {
            $writer->addRow(Row::fromValues([
                (string) ($student['number'] ?? ''),
                $student['name'],
                ...array_map(fn (int|string|null $mark): int|string => match (true) {
                    $mark === null => '',
                    $mark === 'absent' => $words['absent'],
                    default => $mark,
                }, $student['marks']),
                $student['complete'] ? $student['total'] : $words['incomplete'],
                $student['complete'] ? $student['percentage'] : '',
                $student['below'],
                $student['absences'],
            ]));
        }

        $writer->close();
    }
}
