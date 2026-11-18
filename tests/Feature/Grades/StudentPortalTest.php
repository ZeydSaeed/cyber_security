<?php

use App\Models\User;
use App\Modules\Grades\Application\Actions\ChangeEntryWindow;
use App\Modules\Grades\Application\Actions\IssueStudentCodes;
use App\Modules\Grades\Application\Actions\PublishResults;
use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Application\StudentCodes;
use App\Modules\Grades\Database\Factories\SubjectFactory;
use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditEvent;
use App\Platform\Exceptions\DomainRuleViolation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Grades\GradesFixtures;

/*
| Students see their own result (ADR-0034, owner's decisions of
| 2026-11-15): a code the application makes, kept as a digest and
| encrypted; nothing before the owner publishes, and publishing only while
| mark entry is closed; one answer for every wrong code, failures limited.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    RateLimiter::clear('grades-student:minute:127.0.0.1');
    RateLimiter::clear('grades-student:hour:127.0.0.1');
    $this->owner = GradesFixtures::owner();
    $this->subject = Subject::factory()->stage(Stage::Second)->create(['name' => 'Cryptography']);
    $this->student = Student::factory()->named('Huda Salman Ali')->in(Stage::Second, Section::B)->create();
    $this->other = Student::factory()->named('Omar Kareem Jasim')->in(Stage::Second, Section::B)->create();
    app(IssueStudentCodes::class)->missing($this->owner);
    $this->student->refresh();
    $this->code = app(StudentCodes::class)->decrypt((string) $this->student->code_encrypted);

    GradesFixtures::openWindow();
    app(SaveMarks::class)->handle(SubjectFactory::teacherOf($this->subject), $this->subject, Section::B, Period::Month1, [
        $this->student->uuid => Mark::of(82),
        $this->other->uuid => Mark::of(41),
    ]);
});

function publish(object $test): void
{
    app(ChangeEntryWindow::class)->handle($test->owner, Period::Month1, WindowStatus::Closed);
    app(PublishResults::class)->handle($test->owner, Period::Month1, true);
}

describe('codes', function () {
    it('makes readable codes without look-alikes, kept only as a digest and encrypted', function () {
        $codes = app(StudentCodes::class);

        expect($this->code)->toMatch('/^[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{4}-[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{4}$/')
            ->and($this->student->code_digest)->toBe($codes->digest($this->code))
            ->and($this->student->code_encrypted)->not->toContain(str_replace('-', '', $this->code))
            ->and($this->student->toArray())->not->toHaveKeys(['code_digest', 'code_encrypted'])
            // Typed in lower case, with spaces, or without the dash.
            ->and($codes->digest(strtolower(str_replace('-', ' ', $this->code))))->toBe($this->student->code_digest)
            ->and(Student::query()->whereNull('code_digest')->count())->toBe(0)
            ->and(json_encode(AuditEvent::query()->get()->toArray()))->not->toContain($this->code);
    });

    it('replaces a lost code: the old one stops working', function () {
        app(IssueStudentCodes::class)->replace($this->owner, $this->student);
        publish($this);

        $this->post('/student', ['code' => $this->code])->assertSessionHasErrors(['code' => 'This code was not recognised.']);
        $new = app(StudentCodes::class)->decrypt((string) $this->student->fresh()?->code_encrypted);
        $this->post('/student', ['code' => $new])->assertRedirect('/student/result');
    });

    it('prints a group\'s codes for the owner, and records that they were shown', function () {
        $this->actingAs($this->owner)->get('/admin/students/codes?stage=2&section=B')
            ->assertInertia(fn (Assert $page) => $page
                ->component('modules/grades/pages/print/codes')
                ->where('divided', true)
                ->where('studentLink', route('grades.student.login'))
                ->where('students.0.name', 'Huda Salman Ali')
                ->where('students.0.code', $this->code));

        expect(AuditEvent::query()->where('action', 'grades.student_codes_viewed')->sole()->metadata)->toBe(['stage' => 2, 'section' => 'B']);
    });

    it('is the owner\'s business', function () {
        $this->actingAs(User::factory()->withTwoFactor()->create())->get('/admin/students/codes')->assertForbidden();
    });
});

describe('publishing', function () {
    it('shows nothing of the marks before the results are published', function () {
        $this->post('/student', ['code' => $this->code])->assertRedirect('/student/result');

        $this->get('/student/result')->assertInertia(fn (Assert $page) => $page
            ->component('modules/grades/pages/student/result')
            ->where('published', false)
            ->where('card', null)
            ->where('portal.name', 'Huda Salman Ali'));
    });

    it('shows the student only their own result once published', function () {
        publish($this);
        $this->post('/student', ['code' => $this->code]);

        $this->get('/student/result')->assertInertia(fn (Assert $page) => $page
            ->where('published', true)
            ->where('card.student.name', 'Huda Salman Ali')
            ->where('card.student.marks', [82])
            ->where('card.divided', true)
            ->where('card.section', 'B')
            ->missing('card.students'));
    });

    it('publishes only while entry is closed, and opens entry only once hidden again', function () {
        expect(fn () => app(PublishResults::class)->handle($this->owner, Period::Month1, true))
            ->toThrow(DomainRuleViolation::class, 'Close mark entry before publishing the results.');

        publish($this);
        expect(fn () => app(ChangeEntryWindow::class)->handle($this->owner, Period::Month1, WindowStatus::Open))
            ->toThrow(DomainRuleViolation::class, 'Hide the published results before opening mark entry again.');

        $this->actingAs($this->owner)->put('/admin/publish', ['published' => false])->assertRedirect();
        app(ChangeEntryWindow::class)->handle($this->owner, Period::Month1, WindowStatus::Open);

        expect(AuditEvent::query()->where('action', 'grades.results_published')->count())->toBe(1)
            ->and(AuditEvent::query()->where('action', 'grades.results_unpublished')->count())->toBe(1);
    });
});

describe('signing in', function () {
    it('gives one answer to a wrong code, and stops an address after five failures', function () {
        foreach (range(1, 5) as $attempt) {
            $this->post('/student', ['code' => "WRNG-000{$attempt}"])->assertSessionHasErrors(['code' => 'This code was not recognised.']);
        }

        $this->post('/student', ['code' => $this->code])->assertSessionHasErrors('code');
        expect(Auth::guard(Student::GUARD)->check())->toBeFalse()
            ->and(AuditEvent::query()->where('action', 'grades.student_lockout')->count())->toBe(1)
            ->and(AuditEvent::query()->where('action', 'grades.student_login_failed')->first()?->actor_id)->toBeNull();
    });

    it('keeps students, teachers and users apart', function () {
        $this->post('/student', ['code' => $this->code]);

        $this->get('/teacher/subjects')->assertRedirect('/teacher');
        $this->get('/admin')->assertRedirect('/login');
        expect(Auth::guard(Teacher::GUARD)->check())->toBeFalse()
            ->and(AuditEvent::query()->where('action', 'auth.login')->count())->toBe(0);

        $this->post('/student/logout')->assertRedirect('/student');
        $this->get('/student/result')->assertRedirect('/student');
    });

    it('ends the session when the code is replaced, and after 30 idle minutes', function () {
        $this->post('/student', ['code' => $this->code]);
        $this->get('/student/result')->assertOk();

        $this->travel(31)->minutes();
        $this->get('/student/result')->assertRedirect('/student');

        $this->post('/student', ['code' => $this->code]);
        app(IssueStudentCodes::class)->replace($this->owner, $this->student);
        Auth::forgetGuards();
        $this->get('/student/result')->assertRedirect('/student');
    });
});
