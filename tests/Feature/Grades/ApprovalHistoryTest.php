<?php

use App\Modules\Grades\Application\Actions\ApproveSection;
use App\Modules\Grades\Application\Actions\ChangeEntryWindow;
use App\Modules\Grades\Application\Actions\ManageAccess;
use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\SectionApproval;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditEvent;
use App\Platform\Exceptions\DomainRuleViolation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Grades\GradesFixtures;

/*
| A teacher approves a section's marks and they lock; the owner withdraws
| an approval; a change under a grant clears it. Every change of a mark is
| listed for the owner; each subject has its figures (ADR-0041).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    RateLimiter::clear('grades-teacher:minute:127.0.0.1');
    $this->owner = GradesFixtures::owner();
    $this->teacher = Teacher::factory()->withCode('river-stone-81')->create(['name' => 'Huda Karim']);
    $this->subject = Subject::factory()->create(['name' => 'Networks', 'teacher_id' => $this->teacher->id]);
    $this->ali = Student::factory()->named('Ali Hassan')->in(Stage::First, Section::A)->create();
    $this->mona = Student::factory()->named('Mona Salim')->in(Stage::First, Section::A)->create();
    GradesFixtures::openWindow();
});

function teacherSavesMarks(object $test, array $marks): array
{
    return app(SaveMarks::class)->handle($test->teacher->fresh(), $test->subject, Section::A, Period::Month1, $marks);
}

function approveMarks(object $test): SectionApproval
{
    return app(ApproveSection::class)->approve($test->teacher->fresh(), $test->subject, Section::A, Period::Month1);
}

it('approves only a complete section, once', function () {
    teacherSavesMarks($this, [$this->ali->uuid => Mark::of(70)]);
    expect(fn () => approveMarks($this))->toThrow(DomainRuleViolation::class, 'Every student needs a mark before approving.');

    teacherSavesMarks($this, [$this->mona->uuid => Mark::absent()]);
    approveMarks($this);
    expect(fn () => approveMarks($this))->toThrow(DomainRuleViolation::class, 'These marks are already approved.');

    $audit = AuditEvent::query()->where('action', 'grades.section_approved')->sole();
    expect($audit->actor_id)->toBeNull()->and($audit->metadata['teacher_name'])->toBe('Huda Karim');
});

it('locks approved marks for the teacher, from the sheet too', function () {
    teacherSavesMarks($this, [$this->ali->uuid => Mark::of(70), $this->mona->uuid => Mark::of(80)]);

    $this->post('/teacher', ['code' => 'river-stone-81']);
    $this->post("/teacher/subjects/{$this->subject->uuid}/A/approve")->assertSessionHasNoErrors();

    $this->get("/teacher/subjects/{$this->subject->uuid}/A")->assertInertia(fn (Assert $page) => $page
        ->where('access.state', 'approved')
        ->where('access.can_write', false)
        ->whereNot('approved_at', null));

    expect(fn () => teacherSavesMarks($this, [$this->ali->uuid => Mark::of(71)]))
        ->toThrow(DomainRuleViolation::class, 'You approved these marks. Ask the department to change them.');
});

it('lets the owner correct approved marks and keep the approval, or withdraw it', function () {
    teacherSavesMarks($this, [$this->ali->uuid => Mark::of(70), $this->mona->uuid => Mark::of(80)]);
    approveMarks($this);

    app(SaveMarks::class)->handle($this->owner, $this->subject, Section::A, Period::Month1, [$this->ali->uuid => Mark::of(72)]);
    expect(SectionApproval::query()->count())->toBe(1);

    $this->actingAs($this->owner)->delete("/admin/subjects/{$this->subject->uuid}/A/approval")->assertRedirect();
    expect(SectionApproval::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'grades.section_approval_withdrawn')->sole()->actor_id)->toBe($this->owner->id)
        ->and(teacherSavesMarks($this, [$this->ali->uuid => Mark::of(73)])['changed'])->toBe(1);
});

it('clears the approval when the teacher changes marks under a grant', function () {
    teacherSavesMarks($this, [$this->ali->uuid => Mark::of(70), $this->mona->uuid => Mark::of(80)]);
    approveMarks($this);
    app(ChangeEntryWindow::class)->handle($this->owner, Period::Month1, WindowStatus::Closed);
    app(ManageAccess::class)->section($this->owner, $this->subject, Section::A, null, null, 2);

    expect(teacherSavesMarks($this, [$this->ali->uuid => Mark::of(75)])['changed'])->toBe(1)
        ->and(SectionApproval::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'grades.section_approval_cleared')->count())->toBe(1);
});

it('lists every change for the owner, newest first, with who made it', function () {
    teacherSavesMarks($this, [$this->ali->uuid => Mark::of(70)]);
    $this->travel(1)->minutes();
    app(SaveMarks::class)->handle($this->owner, $this->subject, Section::A, Period::Month1, [$this->ali->uuid => Mark::absent()]);

    $this->actingAs($this->owner)->get("/admin/subjects/{$this->subject->uuid}/A")->assertInertia(fn (Assert $page) => $page
        ->has('history', 2)
        ->where('history.0.student', 'Ali Hassan')
        ->where('history.0.from', 70)
        ->where('history.0.to', 'absent')
        ->where('history.0.owner', true)
        ->where('history.0.by', $this->owner->name)
        ->where('history.1.from', null)
        ->where('history.1.to', 70)
        ->where('history.1.by', 'Huda Karim')
        ->where('history.1.owner', false)
        ->where('approval', null));
});

it('gives each subject its figures, and marks approved sections on the overview', function () {
    teacherSavesMarks($this, [$this->ali->uuid => Mark::of(40), $this->mona->uuid => Mark::of(90)]);
    approveMarks($this);

    $this->actingAs($this->owner)->get('/admin/results?stage=1&section=A')->assertInertia(fn (Assert $page) => $page
        ->where('stats.0', ['entered' => 2, 'absent' => 0, 'passed' => 1, 'pass_rate' => 50, 'highest' => 90, 'lowest' => 40, 'mean' => 65]));

    $this->actingAs($this->owner)->get('/admin')->assertInertia(fn (Assert $page) => $page
        ->where('stages.0.subjects.0.approved.A', true));
});
