<?php

namespace App\Modules\Grades\Contracts;

/**
 * The Grades module's public read API (ADR-0004, ADR-0034): what the rest of
 * the application (the dashboard) may know about mark entry without
 * reaching into the module's models.
 */
interface GradesOverview
{
    /**
     * The current period's progress: whether entry is open, marks entered
     * and expected, and how many students, subjects and teachers exist.
     *
     * @return array{period: string, open: bool, entered: int, expected: int, students: int, subjects: int, teachers: int}
     */
    public function progress(): array;
}
