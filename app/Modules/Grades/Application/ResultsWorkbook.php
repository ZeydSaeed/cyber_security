<?php

namespace App\Modules\Grades\Application;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * A section's results as an Excel file (ADR-0034, ADR-0039): one row per
 * student, a column per subject (with its maximum), then total,
 * percentage, failed subjects, absences, rank in the section, overall
 * grade, exemption, behaviour and notes. Headers come translated; marks
 * stay numbers so Excel can sort and sum them, and an absence is the
 * translated word.
 */
final class ResultsWorkbook
{
    /**
     * @param  array<string, mixed>  $sheet  ResultSheets::section()
     * @param  array{number: string, name: string, total: string, percentage: string, below: string, absences: string, absent: string, incomplete: string, title: string, rank: string, grade: string, exempt: string, behaviour: string, notes: string, bands: array<string, string>, behaviours: array<string, string>}  $words
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
            ...array_map(fn (array $subject): string => $subject['name'].' ('.$subject['max'].')', $sheet['subjects']),
            $words['total'],
            $words['percentage'],
            $words['below'],
            $words['absences'],
            $words['rank'],
            $words['grade'],
            $words['exempt'],
            $words['behaviour'],
            $words['notes'],
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
                $student['rank'] ?? '',
                $student['band'] === null ? '' : $words['bands'][$student['band']],
                $student['exempt_all'] ? $words['exempt'] : '',
                $student['behaviour'] === null ? '' : $words['behaviours'][$student['behaviour']],
                (string) ($student['notes'] ?? ''),
            ]));
        }

        $writer->close();
    }
}
