<?php

namespace App\Modules\Grades\Domain;

/**
 * What a result card shows, and the colours of marks (ADR-0039).
 *
 * Each option is on or off. A colour is a name from the design system's
 * status tones, never a colour value (COLOR-DESIGN-SYSTEM.md), and is
 * always shown with words.
 */
final class ResultDisplay
{
    /** Option => shown by default. */
    public const OPTIONS = [
        'total' => true,
        'percentage' => true,
        'rank' => false,
        'failed_count' => true,
        'mark_grade' => false,
        'overall_grade' => false,
        'exemption' => false,
        'max_mark' => true,
        'teachers' => false,
        'kind' => false,
        'student_status' => false,
        'behaviour' => false,
        'notes' => false,
    ];

    /**
     * What a teacher sees of their own students' results (ADR-0040) =>
     * shown by default. Their own subjects' marks are always shown.
     */
    public const TEACHER_OPTIONS = [
        'other_subjects' => false,
        'total' => true,
        'percentage' => true,
        'rank' => false,
        'failed_count' => true,
        'mark_grade' => false,
        'overall_grade' => false,
        'exemption' => false,
        'student_status' => false,
        'behaviour' => false,
        'notes' => false,
    ];

    /** The status tones a mark may take. */
    public const TONES = ['none', 'success', 'warning', 'info', 'danger'];

    /** What is coloured => its tone by default. */
    public const TONED = [
        'fail' => 'danger',
        'fair' => 'warning',
        'exempt' => 'success',
        'excellent' => 'none',
    ];

    /**
     * Saved options with any missing one at its default, unknown ones dropped.
     *
     * @param  array<string, mixed>|null  $saved
     * @return array<string, bool>
     */
    public static function options(?array $saved): array
    {
        $options = [];
        foreach (self::OPTIONS as $key => $default) {
            $options[$key] = is_bool($saved[$key] ?? null) ? $saved[$key] : $default;
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>|null  $saved
     * @return array<string, bool>
     */
    public static function teacherOptions(?array $saved): array
    {
        $options = [];
        foreach (self::TEACHER_OPTIONS as $key => $default) {
            $options[$key] = is_bool($saved[$key] ?? null) ? $saved[$key] : $default;
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>|null  $saved
     * @return array<string, string>
     */
    public static function tones(?array $saved): array
    {
        $tones = [];
        foreach (self::TONED as $key => $default) {
            $tone = $saved[$key] ?? null;
            $tones[$key] = is_string($tone) && in_array($tone, self::TONES, true) ? $tone : $default;
        }

        return $tones;
    }
}
