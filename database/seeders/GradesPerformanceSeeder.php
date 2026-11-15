<?php

namespace Database\Seeders;

use App\Modules\Grades\Application\TeacherCodes;
use App\Modules\Grades\Domain\NameKey;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * A department for the teachers' load test (tests/load/teachers.js,
 * ADR-0034): 16 subjects (8 per stage), one teacher each, PERF_STUDENTS
 * students per section (default 40), and the first month open. Writes
 * storage/app/private/perf/teachers.csv (code, subject UUID) for k6.
 * Local only, and only on platform.performance.database, as PerformanceSeeder.
 *
 *   PERF_STUDENTS=40 php artisan db:seed --class=GradesPerformanceSeeder
 */
class GradesPerformanceSeeder extends Seeder
{
    public function run(TeacherCodes $codes): void
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        $allowed = (string) config('platform.performance.database');

        if (! app()->environment('local') || $database !== $allowed) {
            throw new RuntimeException("GradesPerformanceSeeder runs only locally on [{$allowed}], not on [{$database}].");
        }

        if (Teacher::query()->where('name', 'like', 'Perf Teacher %')->exists()) {
            $this->command?->info('Grades performance data already seeded.');

            return;
        }

        $perSection = (int) config('platform.performance.students_per_section');
        $csv = ['code,subject'];

        foreach (Stage::cases() as $stage) {
            foreach (range(1, 8) as $n) {
                $code = "perf-teacher-{$stage->value}-{$n}";
                $teacher = new Teacher;
                $teacher->forceFill(['name' => "Perf Teacher {$stage->value}.{$n}", 'code_digest' => $codes->digest($code)])->save();

                $subject = new Subject;
                $subject->forceFill(['stage' => $stage, 'name' => "Perf Subject {$stage->value}.{$n}", 'teacher_id' => $teacher->id, 'sort_order' => $n])->save();
                $csv[] = "{$code},{$subject->uuid}";
            }

            foreach ($stage->sections() as $section) {
                foreach (range(1, $perSection) as $n) {
                    $name = "Perf Student {$stage->value}{$section->value} {$n}";
                    $student = new Student;
                    $student->forceFill(['full_name' => $name, 'name_key' => NameKey::of($name), 'stage' => $stage, 'section' => $section])->save();
                }
            }
        }

        GradePeriod::for(Period::Month1)->forceFill(['status' => WindowStatus::Open, 'opened_at' => now(), 'closed_at' => null])->save();

        File::ensureDirectoryExists(storage_path('app/private/perf'));
        File::put(storage_path('app/private/perf/teachers.csv'), implode("\n", $csv)."\n");

        $this->command?->info('Seeded 16 teachers and '.($perSection * 4).' students; wrote storage/app/private/perf/teachers.csv.');
    }
}
