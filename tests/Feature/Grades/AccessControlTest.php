<?php

use App\Models\User;
use App\Modules\Grades\Application\Actions\ChangeEntryWindow;
use App\Modules\Grades\Application\Actions\ManageAccess;
use App\Modules\Grades\Application\Actions\PublishResults;
use App\Modules\Grades\Application\Actions\RequestEdit;
use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Application\StudentCodes;
use App\Modules\Grades\Domain\EditRequestStatus;
use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\StudentAccess;
use App\Modules\Grades\Domain\TeacherAccess;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\GradeEditRequest;
use App\Modules\Grades\Models\GradeMark;
use App\Modules\Grades\Models\GradeSettings;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\SubjectTeacher;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditEvent;
use App\Platform\Exceptions\DomainRuleViolation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Grades\GradesFixtures;

/*
| Who may sign in and change marks (ADR-0037): the department's rules
| (attempt limits, caps on sign-ins, the teachers' default mode and
| editing time, students let in or not), a teacher's and a section's own
| mode, grants, requests to change locked marks, and sign-in counts.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['teacher', 'student'] as $who) {
        RateLimiter::clear("grades-{$who}:minute:127.0.0.1");
        RateLimiter::clear("grades-{$who}:hour:127.0.0.1");
    }

    $this->owner = GradesFixtures::owner();
    $this->access = app(ManageAccess::class);
    $this->teacher = Teacher::factory()->withCode('river-stone-81')->create(['name' => 'Huda Karim']);
    $this->subject = Subject::factory()->stage(Stage::First)->create(['name' => 'Cryptography', 'teacher_id' => $this->teacher->id]);
    $this->students = Student::factory()->in(Stage::First, Section::A)->count(3)->create();
    GradesFixtures::openWindow();
});

/**
 * @param  array<string, mixed>  $changes
 */
function accessSettings(object $test, array $changes): GradeSettings
{
    $settings = GradeSettings::current();

    return $test->access->settings($test->owner, [
        'teacher_attempts_per_minute' => $settings->teacher_attempts_per_minute,
        'teacher_attempts_per_hour' => $settings->teacher_attempts_per_hour,
        'student_attempts_per_minute' => $settings->student_attempts_per_minute,
        'student_attempts_per_hour' => $settings->student_attempts_per_hour,
        'teacher_max_logins' => $settings->teacher_max_logins,
        'student_max_logins' => $settings->student_max_logins,
        'teacher_mode' => $settings->teacher_mode,
        'teacher_edit_hours' => $settings->teacher_edit_hours,
        'student_access' => $settings->student_access,
        ...$changes,
    ]);
}

/**
 * @param  array<string, Mark|null>  $marks
 */
function teacherSaves(object $test, array $marks): array
{
    return app(SaveMarks::class)->handle($test->teacher->fresh(), $test->subject, Section::A, Period::Month1, $marks);
}

function teacherSection(object $test): string
{
    return "/teacher/subjects/{$test->subject->uuid}/A";
}

describe('teachers signing in', function () {
    it('applies the attempt limits the owner sets', function () {
        accessSettings($this, ['teacher_attempts_per_minute' => 2]);

        $this->post('/teacher', ['code' => 'wrong-1'])->assertSessionHasErrors('code');
        $this->post('/teacher', ['code' => 'wrong-2'])->assertSessionHasErrors('code');
        $this->post('/teacher', ['code' => 'river-stone-81'])
            ->assertSessionHasErrors(['code' => 'Too many attempts. Try again in 1 minutes.']);

        expect(Auth::guard(Teacher::GUARD)->check())->toBeFalse();
    });

    it('counts sign-ins, and refuses one past the cap with the usual answer', function () {
        accessSettings($this, ['teacher_max_logins' => 2]);

        foreach (range(1, 2) as $time) {
            $this->post('/teacher', ['code' => 'river-stone-81'])->assertRedirect('/teacher/subjects');
            $this->post('/teacher/logout');
        }

        $this->post('/teacher', ['code' => 'river-stone-81'])
            ->assertSessionHasErrors(['code' => 'This code was not recognised.']);

        $fresh = $this->teacher->fresh();
        expect($fresh?->login_count)->toBe(2)
            ->and($fresh?->last_login_at)->not->toBeNull()
            // The refusal names the teacher for the owner's count, still with no actor.
            ->and(AuditEvent::query()->where('action', 'grades.teacher_login_failed')->sole()->subject_id)->toBe((string) $this->teacher->id);

        // Counting from zero lets them in again.
        $this->access->resetTeacherLogins($this->owner, $this->teacher);
        $this->post('/teacher', ['code' => 'river-stone-81'])->assertRedirect('/teacher/subjects');
    });

    it('ends a blocked teacher\'s session at once and refuses their sign-in', function () {
        $this->post('/teacher', ['code' => 'river-stone-81'])->assertRedirect('/teacher/subjects');

        $this->access->teacher($this->owner, $this->teacher, TeacherAccess::Blocked, null, 'On leave');
        // A new request reads the teacher afresh, as the server would.
        Auth::forgetGuards();

        $this->get('/teacher/subjects')->assertRedirect('/teacher');
        $this->post('/teacher', ['code' => 'river-stone-81'])
            ->assertSessionHasErrors(['code' => 'This code was not recognised.']);

        $audit = AuditEvent::query()->where('action', 'grades.teacher_access_changed')->sole();
        expect($audit->actor_id)->toBe($this->owner->id)
            ->and($audit->changes['access_mode'])->toBe(['from' => null, 'to' => 'blocked'])
            ->and($audit->changes['access_reason'])->toBe(['from' => null, 'to' => 'On leave']);
    });

    it('closes signing in for every teacher, saying so before any code is checked', function () {
        accessSettings($this, ['teacher_mode' => TeacherAccess::Blocked]);

        $this->post('/teacher', ['code' => 'not-a-code'])
            ->assertSessionHasErrors(['code' => 'Signing in is closed for teachers for now.']);

        // A teacher's own mode lets them in.
        $this->access->teacher($this->owner, $this->teacher, TeacherAccess::Edit, null, null);
        $this->post('/teacher', ['code' => 'river-stone-81'])
            ->assertSessionHasErrors(['code' => 'Signing in is closed for teachers for now.']);
    });
});

describe('changing marks', function () {
    it('lets a read-only teacher read the sheet but not change it', function () {
        $this->access->teacher($this->owner, $this->teacher, TeacherAccess::ViewOnly, null, null);

        $this->post('/teacher', ['code' => 'river-stone-81']);
        $this->get(teacherSection($this))->assertInertia(fn (Assert $page) => $page
            ->where('access.state', 'view_only')
            ->where('access.can_write', false));

        expect(fn () => teacherSaves($this, [$this->students[0]->uuid => Mark::of(70)]))
            ->toThrow(DomainRuleViolation::class, 'You can read these marks but not change them. Ask the department.');
    });

    it('locks each mark once its editing time is over, but takes new marks', function () {
        accessSettings($this, ['teacher_mode' => TeacherAccess::Timed, 'teacher_edit_hours' => 24]);
        [$a, $b, $c] = $this->students;

        teacherSaves($this, [$a->uuid => Mark::of(60)]);
        $this->travel(25)->hours();

        // Neither changed nor removed (removing would restart the clock).
        foreach ([Mark::of(90), null] as $change) {
            expect(fn () => teacherSaves($this, [$a->uuid => $change]))
                ->toThrow(DomainRuleViolation::class, 'One mark can no longer be changed: its editing time is over. Ask the department to allow it.');
        }

        expect(teacherSaves($this, [$b->uuid => Mark::of(75)])['changed'])->toBe(1);
        // Still editable within its own 24 hours.
        $this->travel(23)->hours();
        expect(teacherSaves($this, [$b->uuid => Mark::of(76)])['changed'])->toBe(1)
            ->and(GradeMark::query()->where('student_id', $a->id)->value('mark'))->toBe(60);

        $this->post('/teacher', ['code' => 'river-stone-81']);
        $this->get(teacherSection($this))->assertInertia(fn (Assert $page) => $page
            ->where('access.state', 'timed')
            ->where('access.hours', 24)
            ->where('students', fn ($rows) => collect($rows)->firstWhere('uuid', $a->uuid)['locked'] === true
                && collect($rows)->firstWhere('uuid', $c->uuid)['locked'] === false));
    });

    it('takes the teacher\'s own hours before the department\'s', function () {
        accessSettings($this, ['teacher_mode' => TeacherAccess::Timed, 'teacher_edit_hours' => 24]);
        $this->access->teacher($this->owner, $this->teacher, null, 2, null);

        teacherSaves($this, [$this->students[0]->uuid => Mark::of(60)]);
        $this->travel(3)->hours();

        expect(fn () => teacherSaves($this, [$this->students[0]->uuid => Mark::of(61)]))->toThrow(DomainRuleViolation::class);
    });

    it('lets a section\'s own mode come before the teacher\'s', function () {
        $this->access->teacher($this->owner, $this->teacher, TeacherAccess::ViewOnly, null, null);
        $this->access->section($this->owner, $this->subject, Section::A, TeacherAccess::Edit, null, null);

        expect(teacherSaves($this, [$this->students[0]->uuid => Mark::of(70)])['changed'])->toBe(1);
    });

    it('hides a blocked section from its teacher', function () {
        $this->access->section($this->owner, $this->subject, Section::A, TeacherAccess::Blocked, 'Reassigned', null);

        $this->post('/teacher', ['code' => 'river-stone-81']);
        $this->get('/teacher/subjects')->assertInertia(fn (Assert $page) => $page->has('subjects', 0));
        $this->get(teacherSection($this))->assertNotFound();

        expect(fn () => teacherSaves($this, [$this->students[0]->uuid => Mark::of(70)]))->toThrow(AuthorizationException::class);
    });

    it('lets a grant open a closed section until it ends, but never published results', function () {
        app(ChangeEntryWindow::class)->handle($this->owner, Period::Month1, WindowStatus::Closed);
        expect(fn () => teacherSaves($this, [$this->students[0]->uuid => Mark::of(70)]))
            ->toThrow(DomainRuleViolation::class, 'Mark entry is closed for this period.');

        $this->access->section($this->owner, $this->subject, Section::A, null, null, 2);
        expect(teacherSaves($this, [$this->students[0]->uuid => Mark::of(70)])['changed'])->toBe(1);

        $this->travel(3)->hours();
        expect(fn () => teacherSaves($this, [$this->students[0]->uuid => Mark::of(71)]))->toThrow(DomainRuleViolation::class);

        $this->access->section($this->owner, $this->subject, Section::A, null, null, 5);
        app(PublishResults::class)->handle($this->owner, Period::Month1, true);
        expect(fn () => teacherSaves($this, [$this->students[0]->uuid => Mark::of(72)]))
            ->toThrow(DomainRuleViolation::class, 'The results are published; their marks can no longer be changed.');

        // Withdrawn with 0.
        $row = $this->access->section($this->owner, $this->subject, Section::A, null, null, 0);
        expect($row->edit_until)->toBeNull();
    });

    it('still lets the owner correct any mark', function () {
        accessSettings($this, ['teacher_mode' => TeacherAccess::ViewOnly]);
        app(ChangeEntryWindow::class)->handle($this->owner, Period::Month1, WindowStatus::Closed);

        expect(app(SaveMarks::class)->handle($this->owner, $this->subject, Section::A, Period::Month1, [$this->students[0]->uuid => Mark::of(50)])['changed'])->toBe(1);
    });
});

describe('requests to change marks', function () {
    beforeEach(function () {
        app(ChangeEntryWindow::class)->handle($this->owner, Period::Month1, WindowStatus::Closed);
    });

    it('sends a request from the sheet, one waiting at a time', function () {
        $this->post('/teacher', ['code' => 'river-stone-81']);
        $this->from(teacherSection($this))
            ->post(teacherSection($this).'/request', ['reason' => 'Student 3 sat the exam late.'])
            ->assertRedirect(teacherSection($this));

        $this->from(teacherSection($this))
            ->post(teacherSection($this).'/request', ['reason' => 'Again, please.'])
            ->assertSessionHasErrors(['reason' => 'Your request for this section is still waiting for an answer.']);

        $this->get(teacherSection($this))->assertInertia(fn (Assert $page) => $page
            ->where('access.state', 'closed')
            ->where('request.status', 'pending')
            ->where('request.reason', 'Student 3 sat the exam late.'));

        $audit = AuditEvent::query()->where('action', 'grades.edit_requested')->sole();
        expect($audit->actor_id)->toBeNull()
            ->and($audit->metadata['teacher_name'])->toBe('Huda Karim');
    });

    it('refuses a reason too short', function () {
        expect(fn () => app(RequestEdit::class)->handle($this->teacher, $this->subject, Section::A, Period::Month1, 'no'))
            ->toThrow(DomainRuleViolation::class, 'Say why in 5 to 500 characters.');
    });

    it('opens the section for the approved hours, and answers a request once', function () {
        $request = app(RequestEdit::class)->handle($this->teacher, $this->subject, Section::A, Period::Month1, 'A typing mistake.');

        $this->actingAs($this->owner)
            ->put("/admin/access/requests/{$request->uuid}", ['decision' => 'approve', 'hours' => 6])
            ->assertRedirect();

        $request->refresh();
        expect($request->status)->toBe(EditRequestStatus::Approved)
            ->and($request->granted_hours)->toBe(6)
            ->and($request->decided_by_user_id)->toBe($this->owner->id)
            ->and(SubjectTeacher::query()->sole()->edit_until?->isFuture())->toBeTrue()
            ->and(teacherSaves($this, [$this->students[0]->uuid => Mark::of(66)])['changed'])->toBe(1);

        $this->actingAs($this->owner)
            ->put("/admin/access/requests/{$request->uuid}", ['decision' => 'reject'])
            ->assertSessionHasErrors(['request' => 'This request has already been answered.']);
    });

    it('rejects a request, and the section stays closed', function () {
        $request = app(RequestEdit::class)->handle($this->teacher, $this->subject, Section::A, Period::Month1, 'A typing mistake.');
        $this->access->decide($this->owner, $request, false, null);

        expect($request->fresh()?->status)->toBe(EditRequestStatus::Rejected)
            ->and(SubjectTeacher::query()->sole()->edit_until)->toBeNull()
            ->and(GradeEditRequest::query()->count())->toBe(1);
    });
});

describe('students signing in', function () {
    beforeEach(function () {
        $this->student = $this->students[0];
        $codes = app(StudentCodes::class);
        $this->code = 'ABCD-EFGH';
        $this->student->forceFill(['code_digest' => $codes->digest($this->code), 'code_encrypted' => $codes->encrypt($this->code)])->save();
    });

    it('counts sign-ins and closes signing in for every student', function () {
        $this->post('/student', ['code' => $this->code])->assertRedirect('/student/result');
        expect($this->student->fresh()?->login_count)->toBe(1);

        accessSettings($this, ['student_access' => StudentAccess::Blocked]);
        Auth::forgetGuards();
        $this->get('/student/result')->assertRedirect('/student');
        $this->post('/student', ['code' => $this->code])
            ->assertSessionHasErrors(['code' => 'Signing in is closed for students for now.']);
    });

    it('stops one student, and caps sign-ins', function () {
        $this->access->student($this->owner, $this->student, true);
        $this->post('/student', ['code' => $this->code])->assertSessionHasErrors(['code' => 'This code was not recognised.']);

        $this->access->student($this->owner, $this->student, false);
        accessSettings($this, ['student_max_logins' => 1]);
        $this->post('/student', ['code' => $this->code])->assertRedirect('/student/result');
        $this->post('/student/logout');
        $this->post('/student', ['code' => $this->code])->assertSessionHasErrors(['code' => 'This code was not recognised.']);

        expect($this->access->resetStudentLogins($this->owner))->toBe(1);
        $this->post('/student', ['code' => $this->code])->assertRedirect('/student/result');
    });
});

describe('the owner\'s page', function () {
    it('shows the access panel to those who manage', function () {
        $request = app(RequestEdit::class)->handle($this->teacher, $this->subject, Section::A, Period::Month1, 'A typing mistake.');

        $this->actingAs($this->owner)->get('/admin')->assertInertia(fn (Assert $page) => $page
            ->where('access.settings.teacher_mode', 'edit')
            ->where('access.settings.teacher_attempts_per_minute', 5)
            ->where('access.teachers.0.name', 'Huda Karim')
            ->where('access.teachers.0.effective_mode', 'edit')
            ->where('access.teachers.0.sections.0.section', 'A')
            ->where('access.requests.0.uuid', $request->uuid)
            ->where('access.students.total', 3));
    });

    it('refuses the access routes to someone without grades.manage', function () {
        $user = User::factory()->withTwoFactor()->create();

        $this->actingAs($user)->put('/admin/access', [])->assertForbidden();
        $this->actingAs($user)->put("/admin/access/teachers/{$this->teacher->uuid}", ['mode' => 'blocked'])->assertForbidden();
        expect($this->teacher->fresh()?->access_mode)->toBeNull();
    });

    it('validates the settings and keeps the timed mode with hours', function () {
        $this->actingAs($this->owner)->put('/admin/access', [
            'teacher_attempts_per_minute' => 10,
            'teacher_attempts_per_hour' => 5,
            'student_attempts_per_minute' => 5,
            'student_attempts_per_hour' => 20,
            'teacher_mode' => 'timed',
            'student_access' => 'allowed',
        ])->assertSessionHasErrors(['teacher_attempts_per_hour']);

        $this->actingAs($this->owner)->put('/admin/access', [
            'teacher_attempts_per_minute' => 5,
            'teacher_attempts_per_hour' => 20,
            'student_attempts_per_minute' => 5,
            'student_attempts_per_hour' => 20,
            'teacher_mode' => 'timed',
            'student_access' => 'allowed',
        ])->assertSessionHasErrors(['teacher_edit_hours' => 'Give the editing time in hours for the timed mode.']);

        $this->actingAs($this->owner)->put('/admin/access', [
            'teacher_attempts_per_minute' => 3,
            'teacher_attempts_per_hour' => 20,
            'student_attempts_per_minute' => 5,
            'student_attempts_per_hour' => 20,
            'teacher_max_logins' => '',
            'teacher_mode' => 'timed',
            'teacher_edit_hours' => 48,
            'student_access' => 'allowed',
        ])->assertSessionHasNoErrors();

        $settings = GradeSettings::current();
        $audit = AuditEvent::query()->where('action', 'grades.access_settings_changed')->sole();
        expect($settings->teacher_mode)->toBe(TeacherAccess::Timed)
            ->and($settings->teacher_edit_hours)->toBe(48)
            ->and($audit->changes['teacher_attempts_per_minute'])->toBe(['from' => 5, 'to' => 3])
            ->and($audit->changes)->not->toHaveKey('student_attempts_per_minute');
    });
});
