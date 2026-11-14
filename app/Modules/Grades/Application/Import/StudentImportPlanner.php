<?php

namespace App\Modules\Grades\Application\Import;

use App\Modules\Grades\Domain\NameKey;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Models\GradeMark;
use App\Modules\Grades\Models\Student;

/**
 * Compares spreadsheet rows with the students already stored (ADR-0034) and
 * decides, row by row: create, update, leave unchanged, or reject with a
 * reason. Never deletes: students missing from the file are only counted.
 *
 * Columns are found by their header (name, stage, section, number, in
 * English or Arabic); without a recognisable header the order is name,
 * stage, section, number. A row is matched by its student number, or else by
 * its name inside its stage (spelling differences in hamza, teh marbuta,
 * diacritics and spaces ignored).
 */
final class StudentImportPlanner
{
    private const HEADERS = [
        'name' => ['name', 'full name', 'student', 'student name', 'الاسم', 'اسم', 'اسم الطالب', 'الاسم الكامل', 'الطالب', 'اسم الطالب الكامل'],
        'stage' => ['stage', 'year', 'grade', 'class', 'الصف', 'المرحله', 'صف', 'مرحله'],
        'section' => ['section', 'الشعبه', 'شعبه'],
        'number' => ['number', 'student number', 'no', 'no.', 'id', 'الرقم', 'رقم', 'رقم الطالب', 'الرقم الامتحاني', 'الرقم التعريفي'],
    ];

    /**
     * @param  list<array{row: int, cells: list<string>}>  $rows
     */
    public function plan(array $rows): StudentImportPlan
    {
        [$columns, $rows] = $this->columns($rows);

        $students = Student::query()->get(['id', 'full_name', 'name_key', 'stage', 'section', 'student_number']);
        $byNumber = $students->filter(fn (Student $s): bool => $s->student_number !== null)->keyBy('student_number');
        $byName = $students->groupBy(fn (Student $s): string => $s->stage->value.'|'.$s->name_key);
        $withMarks = array_flip(GradeMark::query()->distinct()->pluck('student_id')->all());

        $creates = [];
        $updates = [];
        $rejected = [];
        $unchanged = 0;
        $seen = [];
        $matched = [];

        foreach ($rows as ['row' => $row, 'cells' => $cells]) {
            $name = NameKey::clean($cells[$columns['name']] ?? '');
            $stage = Stage::fromText($cells[$columns['stage']] ?? '');
            $section = Section::fromText($cells[$columns['section']] ?? '');
            $number = $columns['number'] === null ? '' : trim($cells[$columns['number']] ?? '');
            $number = $number === '' ? null : $number;
            $key = NameKey::of($name);

            $reason = match (true) {
                mb_strlen($name) < 3 || mb_strlen($key) < 3 => __('The name is missing or too short.'),
                mb_strlen($name) > 160 => __('The name is longer than :max characters.', ['max' => 160]),
                $stage === null => __('The stage must be 1 or 2.'),
                $section === null => __('The section must be A or B.'),
                $number !== null && mb_strlen($number) > 40 => __('The student number is longer than :max characters.', ['max' => 40]),
                default => null,
            };

            if ($reason === null) {
                $identity = $number !== null ? 'n|'.$number : 's|'.$stage->value.'|'.$key;

                if (isset($seen[$identity])) {
                    $reason = __('Repeats row :row of the file.', ['row' => $seen[$identity]]);
                } else {
                    $seen[$identity] = $row;
                }
            }

            if ($reason !== null) {
                $rejected[] = ['row' => $row, 'reason' => self::text($reason)];

                continue;
            }

            // The stored student this row is about, if any.
            $candidates = $byName->get($stage->value.'|'.$key, collect());
            $existing = $number !== null
                ? ($byNumber->get($number) ?? $candidates->first(fn (Student $s): bool => $s->student_number === null))
                : ($candidates->count() > 1 ? false : $candidates->first());

            if ($existing === false) {
                $rejected[] = ['row' => $row, 'reason' => self::text(__('More than one student in this stage has this name; add the student number.'))];

                continue;
            }

            if ($existing === null) {
                $creates[] = ['row' => $row, 'full_name' => $name, 'name_key' => $key, 'stage' => $stage->value, 'section' => $section->value, 'student_number' => $number];

                continue;
            }

            if (isset($matched[$existing->id])) {
                $rejected[] = ['row' => $row, 'reason' => self::text(__('Repeats row :row of the file.', ['row' => $matched[$existing->id]]))];

                continue;
            }
            $matched[$existing->id] = $row;

            $set = array_filter([
                'full_name' => $existing->full_name !== $name ? $name : null,
                'name_key' => $existing->name_key !== $key ? $key : null,
                'stage' => $existing->stage !== $stage ? $stage->value : null,
                'section' => $existing->section !== $section ? $section->value : null,
                // A file without numbers never removes one.
                'student_number' => $number !== null && $existing->student_number !== $number ? $number : null,
            ], fn (mixed $value): bool => $value !== null);

            if (isset($set['stage']) && isset($withMarks[$existing->id])) {
                $rejected[] = ['row' => $row, 'reason' => self::text(__('This student already has marks in stage :stage and cannot change stage.', ['stage' => $existing->stage->value]))];

                continue;
            }

            if ($set === []) {
                $unchanged++;

                continue;
            }

            $changes = [];
            foreach ($set as $field => $value) {
                if ($field !== 'name_key') {
                    $from = $existing->getAttribute($field);
                    $changes[$field] = ['from' => $from instanceof \BackedEnum ? $from->value : $from, 'to' => $value];
                }
            }

            $updates[] = ['row' => $row, 'id' => $existing->id, 'full_name' => $existing->full_name, 'changes' => $changes, 'set' => $set];
        }

        return new StudentImportPlan($creates, $updates, $unchanged, $rejected, $students->count() - count($matched));
    }

    /**
     * The column of each field, and the rows without the header.
     *
     * @param  list<array{row: int, cells: list<string>}>  $rows
     * @return array{0: array{name: int, stage: int, section: int, number: int|null}, 1: list<array{row: int, cells: list<string>}>}
     */
    private function columns(array $rows): array
    {
        $positional = ['name' => 0, 'stage' => 1, 'section' => 2, 'number' => 3];
        $first = $rows[0]['cells'] ?? [];
        $found = [];

        foreach ($first as $index => $cell) {
            $text = NameKey::of($cell);
            foreach (self::HEADERS as $field => $names) {
                if (! isset($found[$field]) && in_array($text, array_map(NameKey::of(...), $names), true)) {
                    $found[$field] = $index;
                }
            }
        }

        if (! isset($found['name'], $found['stage'], $found['section'])) {
            return [$positional, $rows];
        }

        return [['number' => null, ...$found], array_slice($rows, 1)];
    }

    /**
     * The translator's answer is a string for these sentences (an array
     * only for keys naming a whole translation file).
     *
     * @param  array<mixed>|string  $text
     */
    private static function text(array|string $text): string
    {
        return is_string($text) ? $text : '';
    }
}
