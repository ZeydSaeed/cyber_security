<?php

/*
| The Grades module's settings (ADR-0034).
*/

return [
    // A subject at or above this mark is passed (each subject is out of 100).
    'pass_mark' => (int) env('GRADES_PASS_MARK', 50),

    // Subjects each stage is expected to have; the progress page warns when
    // a stage has fewer or more.
    'subjects_per_stage' => 8,

    // The key of the teacher-code digests (TeacherCodes). Empty: derived
    // from APP_KEY.
    'code_key' => env('GRADES_CODE_KEY', ''),

    // The largest student import, in rows.
    'import_max_rows' => 2000,
];
