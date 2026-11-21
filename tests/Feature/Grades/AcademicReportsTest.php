<?php

use App\Models\User;
use App\Modules\Grades\Application\Queries\AcademicReports;
use App\Modules\Grades\Application\Queries\ReportAdvice;
use App\Modules\Grades\Models\SectionApproval;
use App\Modules\Grades\Models\SubjectTeacher;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditEvent;
use Database\Seeders\ReportsCheckSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Grades\GradesFixtures;

/*
| The academic reports (owner's request, 2026-11-20) against the check
| data: ReportsCheckSeeder's department, whose figures are worked out by
| hand in its comment. Every number here is from that comment.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = GradesFixtures::owner();
    $this->people = ReportsCheckSeeder::build($this->owner);
    $this->report = app(AcademicReports::class)->build();
});

/**
 * @param  array<string, mixed>  $report
 * @return array<string, mixed>
 */
function place(array $report, int $stage, string $section): array
{
    foreach ($report['sections'] as $row) {
        if ($row['stage'] === $stage && $row['section'] === $section) {
            return $row;
        }
    }
    throw new RuntimeException("No section {$stage}{$section}.");
}

/**
 * @param  array<string, mixed>  $report
 * @return array<string, mixed>
 */
function subject(array $report, string $name): array
{
    foreach ($report['subjects'] as $row) {
        if ($row['name'] === $name) {
            return $row;
        }
    }
    throw new RuntimeException("No subject {$name}.");
}

/**
 * @param  array<string, mixed>  $report
 * @return array<string, mixed>
 */
function teacherRow(array $report, string $name): array
{
    foreach ($report['teachers'] as $row) {
        if ($row['name'] === $name) {
            return $row;
        }
    }
    throw new RuntimeException("No teacher {$name}.");
}

it('counts each section\'s passes, failures, exemptions and incomplete results', function () {
    expect(place($this->report, 1, 'A'))->toMatchArray([
        'students' => 4, 'complete' => 3, 'incomplete' => 1, 'passed' => 2, 'failed' => 1, 'exempt' => 1,
        'pass_rate' => 66.7, 'fail_rate' => 33.3, 'exempt_rate' => 33.3, 'mean' => 60.7,
        'failed_two_or_more' => 1, 'frequent_absence' => 1,
    ])->and(place($this->report, 1, 'A')['bands'])->toBe(['excellent' => 1, 'very_good' => 0, 'good' => 0, 'fair' => 1, 'pass' => 0, 'fail' => 1]);

    expect(place($this->report, 2, 'A'))->toMatchArray(['students' => 2, 'complete' => 2, 'passed' => 1, 'failed' => 1, 'exempt' => 0, 'pass_rate' => 50.0, 'mean' => 62.5])
        ->and(place($this->report, 2, 'B'))->toMatchArray(['students' => 2, 'complete' => 1, 'incomplete' => 1, 'passed' => 1, 'pass_rate' => 100.0, 'mean' => 55.0])
        ->and(place($this->report, 3, 'A'))->toMatchArray(['students' => 0, 'pass_rate' => null, 'mean' => null]);
});

it('adds the sections up into stages and totals without rounding twice', function () {
    expect($this->report['totals'])->toMatchArray([
        'students' => 8, 'complete' => 6, 'incomplete' => 2, 'passed' => 4, 'failed' => 2, 'exempt' => 1,
        'pass_rate' => 66.7, 'mean' => 60.3,
    ])->and($this->report['totals']['bands'])->toBe(['excellent' => 1, 'very_good' => 1, 'good' => 0, 'fair' => 1, 'pass' => 1, 'fail' => 2]);

    expect($this->report['stages'][1])->toMatchArray(['stage' => 2, 'students' => 4, 'complete' => 3, 'passed' => 2, 'mean' => 60.0]);
});

it('lists the top of each stage by percentage, complete results only', function () {
    $first = collect($this->report['top'][0]['students'])->map(fn (array $row): array => [$row['rank'], $row['name'], $row['percentage']])->all();
    $second = collect($this->report['top'][1]['students'])->map(fn (array $row): array => [$row['rank'], $row['name'], $row['section']])->all();

    expect($first)->toBe([[1, 'Amal', 95.33], [2, 'Basim', 60.0], [3, 'Dalia', 26.67]])
        ->and($second)->toBe([[1, 'Kamil', 'A'], [2, 'Maha', 'B'], [3, 'Layla', 'A']])
        ->and($this->report['top'][2]['students'])->toBe([]);
});

it('summarises each subject and compares it with its stage', function () {
    expect(subject($this->report, 'Mathematics'))->toMatchArray([
        'entered' => 4, 'absent' => 0, 'passed' => 3, 'failed' => 1, 'pass_rate' => 75.0,
        'mean' => 66.8, 'highest' => 95.0, 'lowest' => 40.0, 'stage_mean' => 72.4, 'difference' => -5.6, 'place' => 1, 'of' => 2,
    ])->and(subject($this->report, 'Physics'))->toMatchArray([
        'max' => 50, 'pass' => 25, 'entered' => 3, 'absent' => 1, 'passed' => 2, 'pass_rate' => 66.7,
        'absence_rate' => 33.3, 'mean' => 78.0, 'difference' => 5.6, 'place' => 2,
    ]);

    $networks = subject($this->report, 'Networks');
    expect($networks)->toMatchArray(['entered' => 3, 'passed' => 2, 'pass_rate' => 66.7, 'mean' => 60.0])
        ->and(array_map(fn (array $row): array => [$row['section'], $row['teacher'], $row['entered'], $row['pass_rate']], $networks['sections']))
        ->toBe([['A', 'Teacher Two', 2, 50.0], ['B', null, 1, 100.0]]);
});

it('shows each teacher\'s progress and sign-ins, and the subject sections nobody teaches', function () {
    $one = teacherRow($this->report, 'Teacher One');
    expect($one)->toMatchArray(['logins' => 3, 'complete' => 1, 'approved' => 1, 'state' => 'started'])
        ->and(array_map(fn (array $row): array => [$row['subject'], $row['students'], $row['entered'], $row['approved_at'] !== null], $one['assignments']))
        ->toBe([['Mathematics', 4, 4, true], ['Physics', 4, 3, false]]);

    expect(teacherRow($this->report, 'Teacher Two'))->toMatchArray(['logins' => 1, 'state' => 'approved'])
        ->and(teacherRow($this->report, 'Teacher Three'))->toMatchArray(['logins' => 0, 'state' => 'free'])
        ->and($this->report['unassigned'])->toBe([['subject' => 'Networks', 'stage' => 2, 'section' => 'B', 'divided' => true]]);
});

it('says which month, and when each section was published', function () {
    $published = collect($this->report['period']['places'])->mapWithKeys(fn (array $row): array => ["{$row['stage']}{$row['section']}" => $row['published_at'] !== null])->all();

    expect($this->report['period'])->toMatchArray(['value' => 'month_1', 'label' => 'First month', 'open' => false, 'published_at' => null])
        ->and($published)->toBe(['1A' => true, '2A' => true, '2B' => false, '3A' => false, '3B' => false]);
});

it('counts students\' sign-ins and views of their result', function () {
    $students = collect($this->report['students'])->keyBy('name');

    expect($students['Amal'])->toMatchArray(['logins' => 2, 'views' => 2])
        ->and($students['Basim'])->toMatchArray(['logins' => 1, 'views' => 1])
        ->and($students['Hadi'])->toMatchArray(['logins' => 0, 'views' => 0, 'last_view_at' => null])
        ->and($this->report['activity'])->toMatchArray(['student_logins' => 3, 'teacher_logins' => 4, 'views' => 3]);
});

it('lists the appeals with the subject\'s teacher, the answer and who gave it', function () {
    $appeals = collect($this->report['appeals'])->keyBy('student');

    expect($appeals)->toHaveCount(2)
        ->and($appeals['Dalia'])->toMatchArray(['subject' => 'Physics', 'teacher' => 'Teacher One', 'status' => 'pending', 'decided_by' => null])
        ->and($appeals['Layla'])->toMatchArray(['subject' => 'Networks', 'teacher' => 'Teacher Two', 'status' => 'accepted', 'decided_by' => $this->owner->name, 'decided_by_teacher' => false]);
});

it('dates each subject section\'s marks and counts the corrections', function () {
    $marks = collect($this->report['marks'])->keyBy(fn (array $row): string => "{$row['subject']} {$row['stage']}{$row['section']}");

    expect($marks['Mathematics 1A'])->toMatchArray(['marks' => 4, 'changes' => 5, 'corrections' => 1, 'by_owner' => 0])
        ->and($marks['Physics 1A'])->toMatchArray(['marks' => 3, 'changes' => 3, 'corrections' => 0])
        ->and($marks['Networks 2B'])->toMatchArray(['marks' => 1, 'by_owner' => 1]);
});

it('recommends what the figures call for, most urgent first', function () {
    $advice = app(ReportAdvice::class)->for($this->report);
    $texts = array_column($advice, 'text');

    expect($advice[0]['level'])->toBe('critical')
        ->and($texts)->toContain('Networks in Second stage · Section B has no teacher.')
        ->and($texts)->toContain('1 teachers have not approved all their marks; 0 of them have entered none.')
        // Hadi's result is published with a mark missing; Nabil's is not published.
        ->and($texts)->toContain('1 students have published results with missing marks.')
        ->and($texts)->toContain('1 students failed two subjects or more.')
        ->and($texts)->toContain('1 students are flagged for frequent absence.')
        // 2 of the 6 students whose results are out looked: 33.3%.
        ->and($texts)->toContain('Only 33.3% of the students whose results are published have looked at them.')
        ->and($texts)->toContain('1 appeals wait for an answer.');

    $levels = array_column($advice, 'level');
    $order = ['critical' => 0, 'warning' => 1, 'info' => 2];
    $ranks = array_map(fn (string $level): int => $order[$level], $levels);
    expect($ranks)->toBe(collect($ranks)->sort()->values()->all());
});

it('flags a subject most students failed', function () {
    $report = $this->report;
    $report['subjects'][0] = [...$report['subjects'][0], 'entered' => 20, 'pass_rate' => 40.0, 'difference' => 0.0, 'absence_rate' => 0.0, 'sections' => []];

    $texts = array_column(app(ReportAdvice::class)->for($report), 'text');

    expect($texts)->toContain('Only 40% passed Mathematics (First stage).');
});

it('shows the page to those who read results, audits the visit, and keeps it from others', function () {
    $this->actingAs($this->owner)->get('/admin/reports')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('modules/grades/pages/admin/reports')
        ->where('totals.students', 8)
        ->has('advice')
        ->has('appeals', 2));
    $this->get('/admin/reports')->assertOk();

    expect(AuditEvent::query()->where('action', 'grades.reports_viewed')->count())->toBe(1);

    $this->actingAs(User::factory()->withTwoFactor()->create())->get('/admin/reports')->assertForbidden();
});

it('stays within a fixed number of queries whatever the department\'s size', function () {
    Teacher::factory()->count(20)->create();
    DB::enableQueryLog();
    app(AcademicReports::class)->build();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThan(60);
});

it('agrees with its own recount from the raw tables', function () {
    $this->artisan('grades:reports-check')->expectsOutputToContain('figures agree.')->assertSuccessful();
});

it('says so when a figure differs', function () {
    // A mark written past the actions: the recount sees it, and so do the
    // reports; a counter changed by hand is seen by the recount only.
    Teacher::query()->where('name', 'Teacher One')->update(['login_count' => 99]);
    $this->artisan('grades:reports-check')->assertSuccessful();

    SectionApproval::query()->update(['teacher_id' => null]);
    SubjectTeacher::query()->delete();
    $this->artisan('grades:reports-check')->expectsOutputToContain('differ.')->assertFailed();
});
