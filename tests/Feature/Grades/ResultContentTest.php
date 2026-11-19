<?php

use App\Models\User;
use App\Modules\Grades\Application\Actions\ChangeEntryWindow;
use App\Modules\Grades\Application\Actions\ManageResults;
use App\Modules\Grades\Application\Actions\PublishResults;
use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Application\Actions\SaveSubject;
use App\Modules\Grades\Application\StudentCodes;
use App\Modules\Grades\Domain\Behaviour;
use App\Modules\Grades\Domain\GradeBands;
use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\ResultDisplay;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\WindowStatus;
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
| What a result says and shows (ADR-0039): each subject's maximum, pass and
| exemption marks; grades, exemption and rank in the section; behaviour and
| notes; and only what the owner chose reaches the student.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    RateLimiter::clear('grades-teacher:minute:127.0.0.1');
    RateLimiter::clear('grades-student:minute:127.0.0.1');
    $this->owner = GradesFixtures::owner();
    $this->teacher = Teacher::factory()->withCode('river-stone-81')->create(['name' => 'Huda Karim']);
    $this->lab = Subject::factory()->create(['name' => 'Lab', 'sort_order' => 1, 'teacher_id' => $this->teacher->id]);
    $this->theory = Subject::factory()->create(['name' => 'Theory', 'sort_order' => 2, 'teacher_id' => $this->teacher->id]);
    app(SaveSubject::class)->changeScale($this->owner, $this->lab, 80, null, null);
    $this->ali = Student::factory()->named('Ali Hassan')->in(Stage::First, Section::A)->create();
    $this->mona = Student::factory()->named('Mona Salim')->in(Stage::First, Section::A)->create();
    $this->zaid = Student::factory()->named('Zaid Kareem')->in(Stage::First, Section::A)->create();
    GradesFixtures::openWindow();
});

/**
 * @param  array<string, Mark>  $marks
 */
function ownerSaves(object $test, Subject $subject, array $marks): void
{
    app(SaveMarks::class)->handle($test->owner, $subject->fresh(), Section::A, Period::Month1, $marks);
}

/** The three students' marks: Ali and Mona tie at 75 %, Zaid fails the lab. */
function enterMarks(object $test): void
{
    ownerSaves($test, $test->lab, [$test->ali->uuid => Mark::of(60, 80), $test->mona->uuid => Mark::of(72, 80), $test->zaid->uuid => Mark::of(30, 80)]);
    ownerSaves($test, $test->theory, [$test->ali->uuid => Mark::of(75), $test->mona->uuid => Mark::of(63), $test->zaid->uuid => Mark::of(95)]);
}

function resultSettings(object $test, array $changes = []): void
{
    $test->access = app(ManageResults::class);
    $test->access->settings(
        $test->owner,
        $changes['pass'] ?? 50,
        $changes['exempt_subject'] ?? null,
        $changes['exempt_overall'] ?? null,
        $changes['bands'] ?? GradeBands::DEFAULTS,
        $changes['options'] ?? ResultDisplay::OPTIONS,
        $changes['tones'] ?? ResultDisplay::TONED,
    );
}

describe('subject scales', function () {
    it('takes marks up to the subject\'s own maximum, and says so beside the student', function () {
        $this->post('/teacher', ['code' => 'river-stone-81']);

        $this->get("/teacher/subjects/{$this->lab->uuid}/A")
            ->assertInertia(fn (Assert $page) => $page->where('subject.max', 80));

        $this->from("/teacher/subjects/{$this->lab->uuid}/A")
            ->put("/teacher/subjects/{$this->lab->uuid}/A", ['marks' => [$this->ali->uuid => '81']])
            ->assertSessionHasErrors(["marks.{$this->ali->uuid}" => 'Enter a whole number from 0 to 80, or mark the student absent.']);

        $this->put("/teacher/subjects/{$this->lab->uuid}/A", ['marks' => [$this->ali->uuid => '80']])->assertSessionHasNoErrors();
        // Out of 100 elsewhere: 150 is refused there too.
        $this->from("/teacher/subjects/{$this->theory->uuid}/A")
            ->put("/teacher/subjects/{$this->theory->uuid}/A", ['marks' => [$this->ali->uuid => '150']])
            ->assertSessionHasErrors("marks.{$this->ali->uuid}");
    });

    it('guards the maximum in the action too', function () {
        expect(fn () => ownerSaves($this, $this->lab, [$this->ali->uuid => Mark::of(90, 100)]))
            ->toThrow(DomainRuleViolation::class, 'Marks in this subject are out of 80.');
    });

    it('keeps the maximum above every mark entered, and audits a change', function () {
        ownerSaves($this, $this->lab, [$this->ali->uuid => Mark::of(70, 80)]);

        expect(fn () => app(SaveSubject::class)->changeScale($this->owner, $this->lab, 60, null, null))
            ->toThrow(DomainRuleViolation::class, 'A mark of 70 is already entered; the maximum cannot be lower.');

        $this->actingAs($this->owner)->put("/admin/subjects/{$this->lab->uuid}", ['max_mark' => 90, 'pass_mark' => 45, 'exempt_mark' => ''])
            ->assertSessionHasNoErrors();

        $lab = $this->lab->fresh();
        expect($lab?->max_mark)->toBe(90)
            ->and($lab?->pass_mark)->toBe(45)
            ->and(AuditEvent::query()->where('action', 'grades.subject_scale_changed')->latest('id')->first()?->changes['max_mark'])->toEqual(['from' => 80, 'to' => 90]);
    });

    it('refuses an exemption mark below the pass mark', function () {
        expect(fn () => app(SaveSubject::class)->changeScale($this->owner, $this->lab, 80, 40, 30))
            ->toThrow(DomainRuleViolation::class, 'The exemption mark is from the pass mark to the maximum.');
    });
});

describe('the owner\'s results', function () {
    it('shows maximums, teachers, grades, exemptions and ranks with ties sharing a rank', function () {
        resultSettings($this, ['exempt_subject' => 90, 'exempt_overall' => 85]);
        enterMarks($this);

        $this->actingAs($this->owner)->get('/admin/results?stage=1&section=A')->assertInertia(fn (Assert $page) => $page
            ->where('subjects.0.name', 'Lab')
            ->where('subjects.0.max', 80)
            ->where('subjects.0.pass', 40)
            ->where('subjects.0.exempt', 72)
            ->where('subjects.0.teacher', 'Huda Karim')
            ->where('subjects.1.max', 100)
            // Ali 60/80 + 75 = 135/180 = 75 %; Mona 72/80 + 63 = 75 %: tied.
            ->where('students.0.name', 'Ali Hassan')
            ->where('students.0.percentage', 75)
            ->where('students.0.rank', 1)
            ->where('students.1.rank', 1)
            ->where('students.1.cells.0.exempt', true)
            ->where('students.1.cells.0.band', 'excellent')
            ->where('students.1.band', 'good')
            // Zaid failed the lab: Fail overall, ranked after the tie.
            ->where('students.2.cells.0.passed', false)
            ->where('students.2.cells.0.band', 'fail')
            ->where('students.2.band', 'fail')
            ->where('students.2.rank', 3)
            ->where('students.2.below', 1));
    });

    it('records behaviour and notes, audited, and shows them on the sheet', function () {
        $this->actingAs($this->owner)
            ->put("/admin/results/students/{$this->ali->uuid}/note", ['behaviour' => 'very_good', 'notes' => 'Helps his classmates.'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->get('/admin/results?stage=1&section=A')->assertInertia(fn (Assert $page) => $page
            ->where('students.0.behaviour', 'very_good')
            ->where('students.0.notes', 'Helps his classmates.'));

        $audit = AuditEvent::query()->where('action', 'grades.student_note_saved')->sole();
        expect($audit->changes['behaviour'])->toEqual(['from' => null, 'to' => 'very_good'])
            ->and($audit->actor_id)->toBe($this->owner->id);
    });

    it('validates and saves the result settings', function () {
        $settings = fn (array $overrides) => [
            'pass_percent' => 50,
            'bands' => GradeBands::DEFAULTS,
            'options' => ['total', 'rank'],
            'tones' => ResultDisplay::TONED,
            ...$overrides,
        ];

        $this->actingAs($this->owner)->put('/admin/results/settings', $settings(['bands' => ['excellent' => 70, 'very_good' => 80, 'good' => 60, 'fair' => 55, 'pass' => 50]]))
            ->assertSessionHasErrors(['bands' => 'Each grade starts below the one above it, from 100 down to 0.']);
        $this->actingAs($this->owner)->put('/admin/results/settings', $settings(['exempt_overall_percent' => 40]))
            ->assertSessionHasErrors(['exempt_overall_percent' => 'An exemption cannot be below the pass percentage.']);
        $this->actingAs($this->owner)->put('/admin/results/settings', $settings(['tones' => [...ResultDisplay::TONED, 'fail' => '#ff0000']]))
            ->assertSessionHasErrors('tones.fail');

        $this->actingAs($this->owner)->put('/admin/results/settings', $settings(['pass_percent' => 60]))->assertSessionHasNoErrors();
        // The card's parts are saved from the "Students" box (ADR-0044).
        $this->actingAs($this->owner)->put('/admin/results/student-view', ['options' => ['total', 'rank'], 'appeal_scope' => 'all'])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->get('/admin')->assertInertia(fn (Assert $page) => $page
            ->where('resultSettings.pass_percent', 60)
            ->where('resultSettings.options.rank', true)
            ->where('resultSettings.options.percentage', false));
        expect(AuditEvent::query()->where('action', 'grades.result_settings_changed')->count())->toBe(1);
    });

    it('refuses the settings and notes to someone without grades.manage', function () {
        $user = User::factory()->withTwoFactor()->create();

        $this->actingAs($user)->put('/admin/results/settings', [])->assertForbidden();
        $this->actingAs($user)->put("/admin/results/students/{$this->ali->uuid}/note", ['notes' => 'x'])->assertForbidden();
    });
});

describe('the student\'s card', function () {
    beforeEach(function () {
        enterMarks($this);
        $codes = app(StudentCodes::class);
        $this->mona->forceFill(['code_digest' => $codes->digest('ABCD-EFGH'), 'code_encrypted' => $codes->encrypt('ABCD-EFGH')])->save();
        app(ManageResults::class)->note($this->owner, $this->mona, Period::Month1, Behaviour::Excellent, 'Top of the class.');
        app(ChangeEntryWindow::class)->handle($this->owner, Period::Month1, WindowStatus::Closed);
        app(PublishResults::class)->handle($this->owner, Period::Month1, true);
    });

    it('sends nothing the owner hides (by default: no rank, grades, teachers, behaviour or notes)', function () {
        $this->post('/student', ['code' => 'ABCD-EFGH']);

        $this->get('/student/result')->assertInertia(fn (Assert $page) => $page
            ->where('card.student.total', 135)
            ->where('card.student.percentage', 75)
            ->where('card.student.rank', null)
            ->where('card.student.band', null)
            ->where('card.student.behaviour', null)
            ->where('card.student.notes', null)
            ->where('card.student.cells.0.band', null)
            ->where('card.subjects.0.teacher', null)
            ->where('card.subjects.0.max', 80));
    });

    it('shows what the owner ticks', function () {
        resultSettings($this, ['options' => [...ResultDisplay::OPTIONS, 'rank' => true, 'overall_grade' => true, 'teachers' => true, 'behaviour' => true, 'notes' => true, 'total' => false]]);
        $this->post('/student', ['code' => 'ABCD-EFGH']);

        $this->get('/student/result')->assertInertia(fn (Assert $page) => $page
            ->where('card.student.rank', 1)
            ->where('card.student.band', 'good')
            ->where('card.student.behaviour', 'excellent')
            ->where('card.student.notes', 'Top of the class.')
            ->where('card.subjects.0.teacher', 'Huda Karim')
            ->where('card.student.total', null));
    });
});
