<?php

/*
| The Grades module's settings (ADR-0034).
*/

return [
    // Subjects each stage is expected to have; the progress page warns when
    // a stage has fewer or more.
    'subjects_per_stage' => 8,

    // The key of the teacher-code digests (TeacherCodes). Empty: derived
    // from APP_KEY.
    'code_key' => env('GRADES_CODE_KEY', ''),

    // The academic reports group sign-ins and views by the department's
    // calendar day (times are stored in UTC).
    'report_timezone' => env('GRADES_REPORT_TIMEZONE', 'Asia/Baghdad'),

    // How many days of sign-ins and views the reports chart.
    'report_days' => 60,

    // The sign-in links the links tab and the printed sheets give out
    // (2026-11-21): each portal may have a host of its own. Empty: the
    // portal's address on the host the page was opened on.
    'links' => [
        'teacher' => env('GRADES_TEACHER_LINK', ''),
        'student' => env('GRADES_STUDENT_LINK', ''),
        'admin' => env('GRADES_ADMIN_LINK', ''),
    ],
];
