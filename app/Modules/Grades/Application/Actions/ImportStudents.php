<?php

namespace App\Modules\Grades\Application\Actions;

use App\Models\User;
use App\Modules\Grades\Application\Import\SpreadsheetRows;
use App\Modules\Grades\Application\Import\StudentImportPlan;
use App\Modules\Grades\Application\Import\StudentImportPlanner;
use App\Modules\Grades\Models\Student;
use App\Platform\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Imports students from an .xlsx or .csv file in two steps (ADR-0034):
 * preview() shows what would change; apply() plans again inside one
 * transaction (the students may have changed since the preview) and
 * applies. Imports are serialised by a table lock on PostgreSQL, so two at
 * once cannot both create the same student. Applying the same file twice
 * changes nothing the second time and records nothing.
 */
class ImportStudents
{
    public function __construct(
        private readonly SpreadsheetRows $spreadsheet,
        private readonly StudentImportPlanner $planner,
        private readonly AuditLogger $audit,
        private readonly IssueStudentCodes $codes,
        private readonly ApproveSection $approvals,
    ) {}

    public function preview(User $actor, string $path, string $extension): StudentImportPlan
    {
        EnsureManager::check($actor);

        return $this->planner->plan($this->rows($path, $extension));
    }

    public function apply(User $actor, string $path, string $extension, string $fileName = ''): StudentImportPlan
    {
        $rows = $this->rows($path, $extension);

        return DB::transaction(function () use ($actor, $rows, $fileName): StudentImportPlan {
            if (DB::getDriverName() === 'pgsql') {
                // Reads stay possible; a second import waits for this one.
                DB::statement('LOCK TABLE grade_students IN SHARE ROW EXCLUSIVE MODE');
            }

            EnsureManager::check($actor);
            $plan = $this->planner->plan($rows);

            if (! $plan->changesSomething()) {
                return $plan;
            }

            foreach ($plan->creates as $create) {
                $student = new Student;
                $student->forceFill([
                    'full_name' => $create['full_name'],
                    'name_key' => $create['name_key'],
                    'stage' => $create['stage'],
                    'section' => $create['section'],
                    'student_number' => $create['student_number'],
                ])->save();
                // Every new student gets the code to see their result.
                $this->codes->give($student);
                $this->approvals->releaseForNewcomer($actor, $student);
            }

            foreach ($plan->updates as $update) {
                $student = Student::query()->whereKey($update['id'])->firstOrFail();
                $student->forceFill($update['set'])->save();
                if (array_key_exists('stage', $update['set']) || array_key_exists('section', $update['set'])) {
                    $this->approvals->releaseForNewcomer($actor, $student);
                }
            }

            $this->audit->record('grades.students_imported', null, [
                ...$plan->counts(),
                'file' => mb_substr($fileName, 0, 150),
            ], actor: $actor);

            return $plan;
        }, 3);
    }

    /**
     * @return list<array{row: int, cells: list<string>}>
     */
    private function rows(string $path, string $extension): array
    {
        return $this->spreadsheet->read($path, $extension, (int) config('grades.import_max_rows'));
    }
}
