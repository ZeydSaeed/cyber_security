<?php

use App\Models\User;
use App\Modules\Grades\Application\Actions\SaveTeacher;
use App\Modules\Grades\Http\TeacherSession;
use App\Modules\Grades\Http\TeacherSessionController;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Grades\GradesFixtures;

/*
| Teachers sign in with a code on their own guard (ADR-0034): the same
| answer for every refusal, failures limited per address, sessions that end
| when the code changes, the teacher is stopped or after 30 idle minutes,
| and no way between the teachers' pages and the users' application.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    RateLimiter::clear('grades-teacher:minute:127.0.0.1');
    RateLimiter::clear('grades-teacher:hour:127.0.0.1');
    $this->teacher = Teacher::factory()->withCode('river-stone-81')->create(['name' => 'Huda Karim']);
});

it('shows the code page, and sends a signed-in teacher to their subjects', function () {
    $this->get('/teacher')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('modules/grades/pages/teacher/login'));

    $this->post('/teacher', ['code' => 'river-stone-81'])->assertRedirect('/teacher/subjects');
    $this->get('/teacher')->assertRedirect('/teacher/subjects');
});

it('signs a teacher in with their code, recorded without an actor and without the code', function () {
    $owner = GradesFixtures::owner();

    // Even with the owner signed in on the same browser.
    $this->actingAs($owner)->post('/teacher', ['code' => ' river-stone-81 '])->assertRedirect('/teacher/subjects');

    $audit = AuditEvent::query()->where('action', 'grades.teacher_login')->sole();
    expect(Auth::guard(Teacher::GUARD)->id())->toBe($this->teacher->id)
        ->and(Auth::guard('web')->id())->toBe($owner->id)
        ->and($audit->actor_id)->toBeNull()
        ->and($audit->subject_id)->toBe((string) $this->teacher->id)
        ->and(json_encode($audit->toArray()))->not->toContain('river-stone-81')
        // The users' sign-in record is for users only.
        ->and(AuditEvent::query()->where('action', 'auth.login')->count())->toBe(0)
        ->and($this->teacher->fresh()?->last_seen_at)->not->toBeNull();
});

it('gives one answer to an unknown code and to a stopped teacher', function (string $code) {
    Teacher::factory()->withCode('stopped-code-9')->inactive()->create();

    $this->from('/teacher')->post('/teacher', ['code' => $code])
        ->assertRedirect('/teacher')
        ->assertSessionHasErrors(['code' => 'This code was not recognised.']);

    expect(Auth::guard(Teacher::GUARD)->check())->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'grades.teacher_login_failed')->sole()->actor_id)->toBeNull();
})->with(['unknown' => ['not-a-code-1'], 'stopped' => ['stopped-code-9']]);

it('stops an address after five failures in a minute, even with the right code', function () {
    foreach (range(1, TeacherSessionController::PER_MINUTE) as $attempt) {
        $this->post('/teacher', ['code' => "wrong-code-{$attempt}"])->assertSessionHasErrors('code');
    }

    $this->post('/teacher', ['code' => 'river-stone-81'])
        ->assertSessionHasErrors(['code' => 'Too many attempts. Try again in 1 minutes.']);

    expect(Auth::guard(Teacher::GUARD)->check())->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'grades.teacher_lockout')->count())->toBe(1);

    // A minute later the address may try again; the hour's count goes on.
    $this->travel(61)->seconds();
    $this->post('/teacher', ['code' => 'river-stone-81'])->assertRedirect('/teacher/subjects');
});

it('does not count successful sign-ins against the address', function () {
    foreach (range(1, 8) as $ignored) {
        $this->post('/teacher', ['code' => 'river-stone-81'])->assertRedirect('/teacher/subjects');
        $this->post('/teacher/logout')->assertRedirect('/teacher');
    }

    expect(AuditEvent::query()->where('action', 'grades.teacher_logout')->count())->toBe(8);
});

it('ends the session when the owner changes the code or stops the teacher', function (string $change) {
    $this->post('/teacher', ['code' => 'river-stone-81']);
    $this->get('/teacher/subjects')->assertOk();

    $owner = GradesFixtures::owner();
    $change === 'code'
        ? app(SaveTeacher::class)->changeCode($owner, $this->teacher, 'new-code-123')
        : app(SaveTeacher::class)->setActive($owner, $this->teacher, false);

    // A new request reads the teacher again, as a real one would (the test
    // application would otherwise keep the guard's copy from before).
    Auth::forgetGuards();
    $this->get('/teacher/subjects')->assertRedirect('/teacher');
    expect(Auth::guard(Teacher::GUARD)->check())->toBeFalse();
})->with(['code', 'stopped']);

it('ends the session after 30 minutes without activity, not before', function () {
    $this->post('/teacher', ['code' => 'river-stone-81']);

    $this->travel(TeacherSession::IDLE_MINUTES - 1)->minutes();
    $this->get('/teacher/subjects')->assertOk();

    $this->travel(TeacherSession::IDLE_MINUTES + 1)->minutes();
    $this->get('/teacher/subjects')->assertRedirect('/teacher');
});

it('keeps teachers and users apart', function () {
    // Not signed in as a teacher: the teachers' pages send to the code page.
    $this->actingAs(GradesFixtures::owner())->get('/teacher/subjects')->assertRedirect('/teacher');

    // A teacher is not a user: the users' pages send to the users' sign-in.
    Auth::guard('web')->logout();
    $this->post('/teacher', ['code' => 'river-stone-81']);
    $this->get('/dashboard')->assertRedirect('/login');
    $this->get('/admin/users')->assertRedirect('/login');
});

it('signs out only the teacher, leaving a user on the same browser signed in', function () {
    $owner = User::factory()->withTwoFactor()->create();
    $this->actingAs($owner)->post('/teacher', ['code' => 'river-stone-81']);

    $this->post('/teacher/logout')->assertRedirect('/teacher');

    expect(Auth::guard(Teacher::GUARD)->check())->toBeFalse()
        ->and(Auth::guard('web')->id())->toBe($owner->id);
});
