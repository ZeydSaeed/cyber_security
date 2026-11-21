<?php

namespace App\Modules\Grades\Application\Queries;

/**
 * The sign-in links handed to teachers, students and the administration
 * (2026-11-21): the configured address of each portal (grades.links), or
 * the portal's route on the current host when none is configured.
 */
final class PortalLinks
{
    public static function teacher(): string
    {
        return self::link('teacher', 'grades.teacher.login');
    }

    public static function student(): string
    {
        return self::link('student', 'grades.student.login');
    }

    public static function admin(): string
    {
        return self::link('admin', 'grades.admin.overview');
    }

    private static function link(string $portal, string $route): string
    {
        $configured = trim((string) config("grades.links.{$portal}"));

        return $configured !== '' ? $configured : route($route);
    }
}
