<?php

namespace App\Modules\Grades\Application\Actions;

use App\Models\User;
use App\Modules\Grades\Application\StudentCodes;
use App\Modules\Grades\Models\Student;
use App\Platform\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Gives students their codes (ADR-0034): every student without one, or one
 * student a new one (a lost code; the old one stops working). Audited with
 * the count, never the codes.
 */
class IssueStudentCodes
{
    public function __construct(private readonly AuditLogger $audit, private readonly StudentCodes $codes) {}

    /**
     * Codes for every student who has none. Returns how many were issued.
     */
    public function missing(User $actor): int
    {
        return DB::transaction(function () use ($actor): int {
            EnsureManager::check($actor);
            $issued = 0;

            Student::query()->whereNull('code_digest')->lockForUpdate()->orderBy('id')->each(function (Student $student) use (&$issued): void {
                $this->give($student);
                $issued++;
            });

            if ($issued > 0) {
                $this->audit->record('grades.student_codes_issued', null, ['count' => $issued], actor: $actor);
            }

            return $issued;
        }, 3);
    }

    /**
     * A new code for one student; the old one stops working at once.
     */
    public function replace(User $actor, Student $student): Student
    {
        return DB::transaction(function () use ($actor, $student): Student {
            $locked = Student::query()->whereKey($student->id)->lockForUpdate()->firstOrFail();
            EnsureManager::check($actor);
            $this->give($locked);
            $this->audit->record('grades.student_code_replaced', $locked, actor: $actor);

            return $locked;
        }, 3);
    }

    /**
     * Without a manager check or audit: for actions that create students
     * and record their own audit (SaveStudent).
     */
    public function give(Student $student): void
    {
        // A clash is about one in 10^11 per pair; try again rather than fail.
        do {
            $code = $this->codes->generate();
            $digest = $this->codes->digest($code);
        } while (Student::query()->where('code_digest', $digest)->exists());

        $student->forceFill(['code_digest' => $digest, 'code_encrypted' => $this->codes->encrypt($code)])->save();
    }
}
