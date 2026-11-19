<?php

use App\Modules\Grades\Application\Actions\ApproveSection;
use App\Modules\Grades\Application\Actions\ChangeEntryWindow;
use App\Modules\Grades\Application\Actions\ManageAppeals;
use App\Modules\Grades\Application\Actions\ManageResults;
use App\Modules\Grades\Application\Actions\PublishResults;
use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Application\Actions\SaveStudent;
use App\Modules\Grades\Application\Queries\AccessBoard;
use App\Modules\Grades\Domain\AppealScope;
use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\SectionApproval;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Grades\GradesFixtures;

/*
| Findings of the end-to-end audit of 2026-11-20 (tests/audit):
|
| - An approval says "every student has a mark". A student who joins the
|   section afterwards (added, moved or imported) has none, so the
|   approvals of the subjects they lack a mark in are withdrawn.
| - The owner decides an appeal with the appealed mark in view and a link
|   to the sheet where it is corrected.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = GradesFixtures::owner();
    $this->teacher = Teacher::factory()->withCode('river-stone-81')->create(['name' => 'Huda Karim']);
    $this->subject = Subject::factory()->create(['name' => 'Networks', 'stage' => Stage::Second, 'teacher_id' => $this->teacher->id]);
    $this->ali = Student::factory()->named('Ali Hassan')->in(Stage::Second, Section::A)->create();
    $this->omar = Student::factory()->named('Omar Jalil')->in(Stage::Second, Section::B)->create();
    GradesFixtures::openWindow();

    foreach ([[Section::A, $this->ali], [Section::B, $this->omar]] as [$section, $student]) {
        app(SaveMarks::class)->handle($this->teacher->fresh(), $this->subject, $section, Period::Month1, [$student->uuid => Mark::of(70)]);
        app(ApproveSection::class)->approve($this->teacher->fresh(), $this->subject, $section, Period::Month1);
    }
});

function approvedSections(Subject $subject): array
{
    return SectionApproval::query()->where('subject_id', $subject->id)->orderBy('section')->pluck('section')
        ->map(fn (Section $section): string => $section->value)->all();
}

it('withdraws the approval of a section a new student joins', function () {
    expect(approvedSections($this->subject))->toBe(['A', 'B']);

    app(SaveStudent::class)->create($this->owner, 'Sara Nabil', Stage::Second, Section::A);

    expect(approvedSections($this->subject))->toBe(['B']);
    $audit = AuditEvent::query()->where('action', 'grades.section_approval_cleared')->sole();
    expect($audit->actor_id)->toBe($this->owner->id)
        ->and($audit->metadata['reason'])->toBe('student_joined')
        ->and($audit->metadata['student_name'])->toBe('Sara Nabil');
});

it('keeps the approval when a student who already has the mark moves in', function () {
    // Omar has his mark: moving him to A leaves A complete, so A stays approved.
    app(SaveStudent::class)->update($this->owner, $this->omar, 'Omar Jalil', Stage::Second, Section::A);

    expect(approvedSections($this->subject))->toBe(['A', 'B']);
});

it('withdraws the approval when a student without the mark moves in', function () {
    $new = Student::factory()->named('Zaid Kamil')->in(Stage::Third, Section::A)->create();

    app(SaveStudent::class)->update($this->owner, $new, 'Zaid Kamil', Stage::Second, Section::B);

    expect(approvedSections($this->subject))->toBe(['A']);
});

it('leaves other sections and stages alone, and changes nothing on a rename', function () {
    app(SaveStudent::class)->create($this->owner, 'Huda Ali', Stage::Third, Section::A);
    app(SaveStudent::class)->update($this->owner, $this->ali, 'Ali Hassan Kadhim', Stage::Second, Section::A);

    expect(approvedSections($this->subject))->toBe(['A', 'B'])
        ->and(AuditEvent::query()->where('action', 'grades.section_approval_cleared')->count())->toBe(0);
});

it('shows the owner the appealed mark and the sheet to correct it on', function () {
    app(ManageResults::class)->studentView($this->owner, ['appeals' => true, 'total' => true], 60 * 24, null, AppealScope::All, false);
    app(ChangeEntryWindow::class)->handle($this->owner, Period::Month1, WindowStatus::Closed);
    app(PublishResults::class)->handle($this->owner, Period::Month1, true);

    app(ManageAppeals::class)->submit($this->ali->fresh(), $this->subject, Period::Month1, 'My mark seems too low, please check it.');

    $appeal = app(AccessBoard::class)->show()['appeals'][0];
    expect($appeal['mark'])->toBe(70)
        ->and($appeal['max'])->toBe(100)
        ->and($appeal['sheet'])->toBe(route('grades.admin.sheet', ['subject' => $this->subject->uuid, 'section' => 'A']));
    expect(GradePeriod::for(Period::Month1)->status)->toBe(WindowStatus::Closed);
});
