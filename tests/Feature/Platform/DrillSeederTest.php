<?php

use App\Modules\Grades\Models\GradeMark;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditEvent;
use App\Platform\Files\StoredFile;
use App\Platform\Outbox\OutboxMessage;
use Database\Seeders\DrillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/*
| The restore drill's data (operations.yml) is made through the module's
| own actions, so the drill restores what real use leaves behind.
*/

uses(RefreshDatabase::class);

it('makes a department with marks, files, audit records and outbox messages', function () {
    Storage::fake((string) config('platform.files.disk'));
    config(['platform.performance.drill_students' => 16]);

    $this->seed(DrillSeeder::class);

    // 16 students, 8 per stage, 4 per section; 7 of 8 subjects marked.
    expect(Teacher::query()->count())->toBe(8)
        ->and(Subject::query()->count())->toBe(16)
        ->and(Student::query()->count())->toBe(16)
        ->and(GradeMark::query()->count())->toBe(16 * 7)
        ->and(GradeMark::query()->where('is_absent', true)->count())->toBeGreaterThan(0)
        ->and(StoredFile::query()->count())->toBe(4)
        ->and(AuditEvent::query()->where('action', 'grades.marks_saved')->count())->toBe(28)
        ->and(AuditEvent::query()->where('action', 'like', 'grades.window_%')->count())->toBe(2)
        ->and(OutboxMessage::query()->where('topic', 'grades.section_completed')->count())->toBe(28);
});

it('never runs in production or staging', function (string $environment) {
    app()->detectEnvironment(fn (): string => $environment);

    // Called directly: db:seed itself would first ask for confirmation.
    app()->call([new DrillSeeder, 'run']);
})->with(['production', 'staging'])->throws(RuntimeException::class, 'never runs in production or staging');
