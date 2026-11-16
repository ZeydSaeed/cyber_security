<?php

namespace Database\Seeders;

use App\Access\Role;
use App\Models\User;
use App\Modules\Grades\Application\Actions\ChangeEntryWindow;
use App\Modules\Grades\Application\Actions\ImportStudents;
use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Application\Actions\SaveSubject;
use App\Modules\Grades\Application\Actions\SaveTeacher;
use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\Student;
use App\Platform\Access\RoleAssignment;
use App\Platform\Files\FileStore;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Data for a restore drill on a disposable machine (operations.yml): a
 * department made through the Grades module's own actions (ADR-0034), so
 * audit records, outbox messages and notifications exist as in real use:
 * eight teachers and sixteen subjects, students imported from a file, and
 * marks for most sections. Attached files come from people's documents.
 * Refuses to run in production or staging.
 *
 *   DRILL_STUDENTS=300 php artisan db:seed --class=DrillSeeder
 *   (platform.performance.drill_students)
 */
class DrillSeeder extends Seeder
{
    public function run(
        SaveTeacher $teachers,
        SaveSubject $subjects,
        ImportStudents $import,
        ChangeEntryWindow $window,
        SaveMarks $marks,
        FileStore $files,
    ): void {
        if (app()->environment('production', 'staging')) {
            throw new RuntimeException('DrillSeeder never runs in production or staging.');
        }

        $owner = User::factory()->withTwoFactor()->create(['name' => 'Drill Owner']);
        RoleAssignment::query()->forceCreate(['user_id' => $owner->id, 'role' => Role::Administrator->value]);

        $staff = [];
        foreach (range(1, 8) as $n) {
            $staff[] = $teachers->create($owner, "Drill Teacher {$n}", "drill-code-{$n}");
        }

        $subjectList = [];
        // The drill's figures are for two stages (its students are in 1 and 2).
        foreach ([Stage::First, Stage::Second] as $stage) {
            foreach (range(1, 8) as $n) {
                $subjectList[] = $subjects->create($owner, $stage, "Subject {$stage->value}.{$n}", $staff[($n - 1) % 8]);
            }
        }

        $count = (int) config('platform.performance.drill_students');
        // A private, unpredictable name in the application's own storage.
        $csv = storage_path('app/private/drill-students-'.bin2hex(random_bytes(8)).'.csv');
        $lines = ['name,stage,section,number'];
        for ($i = 1; $i <= $count; $i++) {
            // The first stage has no sections: its section is left empty.
            $stage = $i % 2 + 1;
            $lines[] = "Drill Student {$i},{$stage},".($stage === 1 ? '' : ($i % 4 < 2 ? 'A' : 'B')).",D{$i}";
        }
        file_put_contents($csv, implode("\n", $lines));

        try {
            $import->apply($owner, $csv, 'csv', 'drill.csv');
        } finally {
            @unlink($csv);
        }

        $window->handle($owner, Period::Month1, WindowStatus::Open);

        // Every subject's sections, except the last subject of each stage
        // (left for the "missing marks" view); one absence in every ten.
        foreach ($subjectList as $index => $subject) {
            if ($index % 8 === 7) {
                continue;
            }

            foreach ($subject->stage->sections() as $section) {
                $values = [];
                foreach (Student::query()->where('stage', $subject->stage->value)->where('section', $section->value)->pluck('id', 'uuid') as $uuid => $id) {
                    $values[$uuid] = $id % 10 === 0 ? Mark::absent() : Mark::of(($id * 7 + $index * 3) % 101);
                }
                $marks->handle($staff[$index % 8], $subject, $section, Period::Month1, $values);
            }
        }

        $window->handle($owner, Period::Month1, WindowStatus::Closed);

        $pdf = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";
        foreach (range(1, 4) as $n) {
            $files->store(UploadedFile::fake()->createWithContent("letter-{$n}.pdf", $pdf), null, $owner, $owner);
        }
    }
}
