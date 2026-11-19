<?php

use App\Modules\Grades\Application\Actions\ManageAccess;
use App\Modules\Grades\Application\Actions\SaveTeacher;
use App\Modules\Grades\Application\StudentCodes;
use App\Modules\Grades\Domain\DeviceHolder;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\TeacherAccess;
use App\Modules\Grades\Http\DeviceCookie;
use App\Modules\Grades\Models\GradeSettings;
use App\Modules\Grades\Models\RememberedDevice;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Grades\GradesFixtures;

/*
| "Remember this device" (ADR-0038): opt-in on the code page; the cookie
| holds a random token, never the code; each use replaces it; a new code,
| expiry, the owner, or signing out ends it; a copied token works once.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['teacher', 'student'] as $who) {
        RateLimiter::clear("grades-{$who}:minute:127.0.0.1");
        RateLimiter::clear("grades-{$who}:hour:127.0.0.1");
    }

    $this->owner = GradesFixtures::owner();
    $this->teacher = Teacher::factory()->withCode('river-stone-81')->create(['name' => 'Huda Karim']);
});

/** The device cookie a response sets (null: none, or forgotten). */
function deviceCookie(TestResponse $response, DeviceHolder $kind): ?string
{
    // Decrypted, without the prefix Laravel adds to encrypted cookies.
    $cookie = $response->getCookie(DeviceCookie::name($kind));

    return $cookie !== null && $cookie->getExpiresTime() > time() ? $cookie->getValue() : null;
}

/** A fresh browser: no session, only the device cookie. */
function newBrowser(object $test, DeviceHolder $kind, string $token): TestResponse
{
    Auth::forgetGuards();
    $test->flushSession();

    return $test->withCookie(DeviceCookie::name($kind), $token)->get('/'.$kind->value);
}

it('offers the option on the code page, and not when the owner turned it off', function () {
    $this->get('/teacher')->assertInertia(fn (Assert $page) => $page->where('rememberDays', 30));
    $this->get('/student')->assertInertia(fn (Assert $page) => $page->where('rememberDays', 30));

    GradeSettings::current()->forceFill(['remember_days' => 0])->save();
    $response = $this->post('/teacher', ['code' => 'river-stone-81', 'remember' => '1']);

    expect(deviceCookie($response, DeviceHolder::Teacher))->toBeNull()
        ->and(RememberedDevice::query()->count())->toBe(0);
});

it('remembers a teacher only when asked, and lets them straight in next time', function () {
    $plain = $this->post('/teacher', ['code' => 'river-stone-81']);
    expect(deviceCookie($plain, DeviceHolder::Teacher))->toBeNull();
    $this->post('/teacher/logout');

    $response = $this->post('/teacher', ['code' => 'river-stone-81', 'remember' => '1'])->assertRedirect('/teacher/subjects');
    $token = deviceCookie($response, DeviceHolder::Teacher);
    $device = RememberedDevice::query()->sole();

    expect($token)->toBeString()
        // Never the code, in the cookie or the table.
        ->and($token)->not->toContain('river-stone-81')
        ->and(json_encode($device->getAttributes()))->not->toContain('river-stone-81')
        ->and($device->validator_hash)->not->toBe(explode('.', (string) $token)[1])
        ->and($device->expires_at->diffInDays(now(), true))->toBeGreaterThan(29.9);

    $next = newBrowser($this, DeviceHolder::Teacher, (string) $token)->assertRedirect('/teacher/subjects');

    expect(Auth::guard(Teacher::GUARD)->id())->toBe($this->teacher->id)
        // A new token each time; the sign-in counted and audited as remembered.
        ->and(deviceCookie($next, DeviceHolder::Teacher))->toBeString()->not->toBe($token)
        ->and($this->teacher->fresh()?->login_count)->toBe(3)
        ->and(AuditEvent::query()->where('action', 'grades.teacher_login')->latest('id')->first()?->metadata['remembered'] ?? null)->toBeTrue();
});

it('lets a copied token in once at most, and forgets the device when it is reused', function () {
    $token = (string) deviceCookie($this->post('/teacher', ['code' => 'river-stone-81', 'remember' => '1']), DeviceHolder::Teacher);

    newBrowser($this, DeviceHolder::Teacher, $token)->assertRedirect('/teacher/subjects');
    $again = newBrowser($this, DeviceHolder::Teacher, $token)->assertOk();

    expect(Auth::guard(Teacher::GUARD)->check())->toBeFalse()
        ->and(RememberedDevice::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'grades.device_token_mismatch')->count())->toBe(1);
    $again->assertInertia(fn (Assert $page) => $page->component('modules/grades/pages/teacher/login'));
});

it('ends when the code changes, the teacher is stopped, or it expires', function (string $how) {
    $token = (string) deviceCookie($this->post('/teacher', ['code' => 'river-stone-81', 'remember' => '1']), DeviceHolder::Teacher);

    match ($how) {
        'code' => app(SaveTeacher::class)->changeCode($this->owner, $this->teacher, 'new-code-42'),
        'stopped' => app(SaveTeacher::class)->setActive($this->owner, $this->teacher, false),
        'blocked' => app(ManageAccess::class)->teacher($this->owner, $this->teacher, TeacherAccess::Blocked, null, null),
        'expired' => $this->travel(31)->days(),
    };

    newBrowser($this, DeviceHolder::Teacher, $token)->assertOk();
    expect(Auth::guard(Teacher::GUARD)->check())->toBeFalse();
})->with(['code', 'stopped', 'blocked', 'expired']);

it('forgets this device on signing out', function () {
    $token = (string) deviceCookie($this->post('/teacher', ['code' => 'river-stone-81', 'remember' => '1']), DeviceHolder::Teacher);

    $out = $this->withCookie(DeviceCookie::name(DeviceHolder::Teacher), $token)->post('/teacher/logout');

    expect(RememberedDevice::query()->count())->toBe(0)
        ->and(deviceCookie($out, DeviceHolder::Teacher))->toBeNull();
    newBrowser($this, DeviceHolder::Teacher, $token)->assertOk();
});

it('lets the owner forget every device of a teacher, audited', function () {
    $this->post('/teacher', ['code' => 'river-stone-81', 'remember' => '1']);
    $this->post('/teacher', ['code' => 'river-stone-81', 'remember' => '1']);

    $this->actingAs($this->owner)->get('/admin')
        ->assertInertia(fn (Assert $page) => $page->where('access.teachers.0.devices', 2));

    $this->actingAs($this->owner)->delete("/admin/access/teachers/{$this->teacher->uuid}/devices")->assertRedirect();

    expect(RememberedDevice::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'grades.devices_forgotten')->sole()->metadata['devices'])->toBe(2);
});

describe('students', function () {
    beforeEach(function () {
        $this->student = Student::factory()->in(Stage::First, Section::A)->create();
        $codes = app(StudentCodes::class);
        $this->code = 'ABCD-EFGH';
        $this->student->forceFill(['code_digest' => $codes->digest($this->code), 'code_encrypted' => $codes->encrypt($this->code)])->save();
    });

    it('remembers a student until a new code', function () {
        $token = (string) deviceCookie($this->post('/student', ['code' => $this->code, 'remember' => '1']), DeviceHolder::Student);
        $next = (string) deviceCookie(newBrowser($this, DeviceHolder::Student, $token)->assertRedirect('/student/result'), DeviceHolder::Student);

        // The teachers' cookie is another.
        expect(DeviceCookie::name(DeviceHolder::Student))->not->toBe(DeviceCookie::name(DeviceHolder::Teacher));

        $this->student->forceFill(['code_digest' => app(StudentCodes::class)->digest('WXYZ-2345')])->save();
        newBrowser($this, DeviceHolder::Student, $next)->assertOk();

        expect(Auth::guard(Student::GUARD)->check())->toBeFalse()
            ->and(RememberedDevice::query()->count())->toBe(0);
    });

    it('does not let a stopped student in, and the owner forgets the devices', function () {
        $token = (string) deviceCookie($this->post('/student', ['code' => $this->code, 'remember' => '1']), DeviceHolder::Student);

        app(ManageAccess::class)->student($this->owner, $this->student, true);
        newBrowser($this, DeviceHolder::Student, $token)->assertOk();
        expect(Auth::guard(Student::GUARD)->check())->toBeFalse();

        $this->actingAs($this->owner)->delete("/admin/access/students/{$this->student->uuid}/devices")->assertRedirect();
        expect(RememberedDevice::query()->count())->toBe(0);
    });
});
