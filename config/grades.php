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
];
