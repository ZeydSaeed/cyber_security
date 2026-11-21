<?php

namespace App\Modules\Grades\Application\Console;

use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\SubjectTeacher;
use App\Modules\Grades\Models\Teacher;

/**
 * One section of a subject with its teacher, for grades:flow-check: the
 * assignment row with its subject and teacher read and present.
 */
final readonly class FlowAssignment
{
    public function __construct(
        public SubjectTeacher $row,
        public Subject $subject,
        public Teacher $teacher,
        public Section $section,
    ) {}
}
