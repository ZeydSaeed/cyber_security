<?php

use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Models\SectionApproval;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Support\Grades\GradesFixtures;

/*
| A school's teachers sign in from one address (NAT) when an entry window
| opens. The review load test (2026-11-18) found the 31st sign-in in a
| minute refused with 429; the flood limit now allows a whole staff, and
| failed attempts are still limited per address by the controller.
*/

uses(RefreshDatabase::class);

it('lets a whole staff sign in from one address within a minute', function () {
    foreach (range(1, 60) as $n) {
        Teacher::factory()->withCode("shared-address-{$n}")->create();
    }

    foreach (range(1, 60) as $n) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->post('/teacher', ['code' => "shared-address-{$n}"])
            ->assertRedirect('/teacher/subjects');

        Auth::guard(Teacher::GUARD)->logout();
        $this->flushSession();
    }
});

it('still stops guessing from one address', function () {
    foreach (range(1, 5) as $n) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.8'])
            ->post('/teacher', ['code' => "wrong-code-{$n}"])
            ->assertSessionHasErrors('code');
    }

    Teacher::factory()->withCode('right-code-1')->create();

    // The sixth attempt is refused even with a right code: locked out.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.8'])
        ->post('/teacher', ['code' => 'right-code-1'])
        ->assertSessionHasErrors('code');

    expect(Auth::guard(Teacher::GUARD)->check())->toBeFalse();
});

/*
| The full-size audit (2026-11-20) found the same for approving: 10 a
| minute per address refused the fourth teacher of a school approving at
| once (429). Approving, asking for changes and appealing are now limited
| per teacher or student; one person is still held to the limit.
*/
it('lets a whole staff approve from one address within a minute', function () {
    GradesFixtures::openWindow();
    // One student, so each subject's only section is complete with one mark.
    $student = Student::factory()->in(Stage::First, Section::A)->create();
    $teachers = [];

    foreach (range(1, 15) as $n) {
        $teacher = Teacher::factory()->withCode("approve-shared-{$n}")->create();
        $subject = Subject::factory()->create(['name' => "Subject {$n}", 'teacher_id' => $teacher->id]);
        app(SaveMarks::class)->handle($teacher, $subject, Section::A, Period::Month1, [$student->uuid => Mark::of(60)]);
        $teachers[] = [$teacher, $subject];
    }

    foreach ($teachers as $n => [$teacher, $subject]) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->post('/teacher', ['code' => 'approve-shared-'.($n + 1)])
            ->assertRedirect('/teacher/subjects');

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->post("/teacher/subjects/{$subject->uuid}/A/approve");
        expect($response->status())->not->toBe(429);

        Auth::guard(Teacher::GUARD)->logout();
        $this->flushSession();
    }

    expect(SectionApproval::query()->count())->toBe(15);
});

it('still holds one teacher to ten approvals a minute', function () {
    $teacher = Teacher::factory()->withCode('one-busy-teacher')->create();
    $this->post('/teacher', ['code' => 'one-busy-teacher'])->assertRedirect('/teacher/subjects');
    $subject = Subject::factory()->create(['teacher_id' => $teacher->id]);

    foreach (range(1, 10) as $n) {
        expect($this->post("/teacher/subjects/{$subject->uuid}/A/approve")->status())->not->toBe(429);
    }
    $this->post("/teacher/subjects/{$subject->uuid}/A/approve")->assertStatus(429);
});
