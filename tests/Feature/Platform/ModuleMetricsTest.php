<?php

use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\GradeMark;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Platform\Observability\MetricsSource;
use App\Platform\Observability\MetricsWriter;
use App\Platform\Observability\PlatformMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| Modules add metrics through MetricsSource (ADR-0023, ADR-0034): under
| their own prefix, and without breaking the scrape when they fail.
*/

uses(RefreshDatabase::class);

it('exports the Grades module\'s metrics through its source', function () {
    $subjects = Subject::factory()->count(2)->create();
    Subject::factory()->stage(Stage::Second)->create();
    $students = Student::factory()->count(3)->create();
    Student::factory()->in(Stage::Second, Section::B)->count(2)->create();
    GradePeriod::for(Period::Month1)->forceFill(['status' => WindowStatus::Open, 'opened_at' => now()])->save();

    foreach ($students as $student) {
        (new GradeMark)->forceFill([
            'student_id' => $student->id, 'subject_id' => $subjects[0]->id, 'stage' => Stage::First,
            'period' => Period::Month1, 'mark' => 70, 'entered_by_teacher_id' => $subjects[0]->teacher_id,
        ])->save();
    }

    $text = app(PlatformMetrics::class)->render();

    // Expected: 3 students x 2 subjects + 2 students x 1 subject.
    expect($text)->toContain('platform_metrics_source_up{source="grades"} 1')
        ->and($text)->toContain('module_grades_students{stage="1"} 3')
        ->and($text)->toContain('module_grades_students{stage="2"} 2')
        ->and($text)->toContain('module_grades_marks_entered{period="month_1"} 3')
        ->and($text)->toContain('module_grades_marks_expected{period="month_1"} 8')
        ->and($text)->toContain('module_grades_entry_open{period="month_1"} 1');
});

it('exports every series at zero when nothing exists, so none ever disappears', function () {
    expect(app(PlatformMetrics::class)->render())
        ->toContain("module_grades_marks_entered{period=\"month_1\"} 0\n")
        ->toContain("module_grades_entry_open{period=\"month_1\"} 0\n");
});

it('keeps the scrape working when a source fails, and marks that source down', function () {
    app()->bind('test.failing-source', fn () => new class implements MetricsSource
    {
        public function name(): string
        {
            return 'broken';
        }

        public function collect(MetricsWriter $metrics): void
        {
            $metrics->gauge('module_broken_half_written', 'Written before the failure.', 1);

            throw new RuntimeException('database refused');
        }
    });
    app()->tag(['test.failing-source'], MetricsSource::TAG);

    $text = app(PlatformMetrics::class)->render();

    expect($text)->toContain('platform_metrics_source_up{source="broken"} 0')
        ->and($text)->toContain('platform_metrics_source_up{source="grades"} 1')
        ->and($text)->toContain('platform_up 1')
        // Nothing of a failed source is half exported.
        ->and($text)->not->toContain('module_broken_half_written');
});

it('accepts only names under the module\'s own prefix', function (string $name) {
    (new MetricsWriter('module_grades_'))->gauge($name, 'x', 1);
})->with([
    'a platform name' => ['platform_up'],
    'another module' => ['module_files_count'],
    'not snake case' => ['module_grades_Waiting'],
])->throws(InvalidArgumentException::class);
