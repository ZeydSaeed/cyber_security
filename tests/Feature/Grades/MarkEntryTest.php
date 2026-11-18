<?php

use App\Models\User;
use App\Modules\Grades\Application\Actions\ChangeEntryWindow;
use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Application\Jobs\NotifySectionCompleted;
use App\Modules\Grades\Database\Factories\SubjectFactory;
use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\GradeMark;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditEvent;
use App\Platform\Exceptions\DomainRuleViolation;
use App\Platform\Outbox\OutboxMessage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\Support\Grades\GradesFixtures;

/*
| SaveMarks (ADR-0034): a teacher writes their own subjects while the
| window is open; the owner may correct at any time; only changes are
| written and audited; a completed section is announced once.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    // The second stage: the one with sections.
    $this->subject = Subject::factory()->stage(Stage::Second)->create(['name' => 'Network Security']);
    $this->teacher = SubjectFactory::teacherOf($this->subject);
    $this->students = Student::factory()->in(Stage::Second, Section::A)->count(3)->create()->pluck('uuid')->all();
    // Same stage, other section; and the other stage.
    $this->otherSection = Student::factory()->in(Stage::Second, Section::B)->create();
    $this->otherStage = Student::factory()->in(Stage::First, Section::A)->create();
    GradesFixtures::openWindow();
});

/**
 * @param  array<string, Mark|null>  $marks
 * @return array{changed: int, complete: bool}
 */
function saveMarks(Teacher|User $author, Subject $subject, array $marks, Section $section = Section::A): array
{
    return app(SaveMarks::class)->handle($author, $subject, $section, Period::Month1, $marks);
}

it('writes marks and absences, and records each change with the teacher named', function () {
    [$a, $b] = $this->students;

    $result = saveMarks($this->teacher, $this->subject, [$a => Mark::of(88), $b => Mark::absent()]);

    expect($result)->toBe(['changed' => 2, 'complete' => false])
        ->and(GradeMark::query()->where('is_absent', true)->count())->toBe(1)
        ->and(GradeMark::query()->where('mark', 88)->value('entered_by_teacher_id'))->toBe($this->teacher->id);

    $audit = AuditEvent::query()->where('action', 'grades.marks_saved')->sole();
    expect($audit->actor_id)->toBeNull()
        ->and($audit->subject_type)->toBe(Subject::MORPH)
        ->and($audit->metadata)->toMatchArray(['section' => 'A', 'period' => 'month_1', 'count' => 2, 'teacher' => $this->teacher->uuid, 'teacher_name' => $this->teacher->name])
        // jsonb keeps keys in its own order: compared without order.
        ->and($audit->changes)->toEqual([$a => ['from' => null, 'to' => 88], $b => ['from' => null, 'to' => 'absent']])
        ->and($this->teacher->fresh()?->last_seen_at)->not->toBeNull();
});

it('writes only what changed, and nothing at all for a repeated save', function () {
    [$a, $b] = $this->students;
    saveMarks($this->teacher, $this->subject, [$a => Mark::of(50), $b => Mark::of(60)]);

    $again = saveMarks($this->teacher, $this->subject, [$a => Mark::of(50), $b => Mark::of(60)]);
    $changed = saveMarks($this->teacher, $this->subject, [$a => Mark::of(50), $b => Mark::of(65)]);

    expect($again['changed'])->toBe(0)
        ->and($changed['changed'])->toBe(1)
        ->and(AuditEvent::query()->where('action', 'grades.marks_saved')->count())->toBe(2)
        ->and(AuditEvent::query()->where('action', 'grades.marks_saved')->latest('id')->first()?->changes)->toEqual([$b => ['from' => 60, 'to' => 65]]);
});

it('removes a mark when asked to', function () {
    [$a] = $this->students;
    saveMarks($this->teacher, $this->subject, [$a => Mark::of(40)]);

    expect(saveMarks($this->teacher, $this->subject, [$a => null])['changed'])->toBe(1)
        ->and(GradeMark::query()->count())->toBe(0)
        ->and(saveMarks($this->teacher, $this->subject, [$a => null])['changed'])->toBe(0);
});

it('announces a completed section once, and tells those who manage grades', function () {
    $owner = GradesFixtures::owner();
    $marks = array_fill_keys($this->students, Mark::of(70));
    [$a] = $this->students;

    $result = saveMarks($this->teacher, $this->subject, $marks);
    saveMarks($this->teacher, $this->subject, [$a => Mark::of(71)]);

    $messages = OutboxMessage::query()->where('topic', SaveMarks::TOPIC)->get();
    expect($result['complete'])->toBeTrue()
        ->and($messages)->toHaveCount(1)
        ->and($messages[0]->payload)->toEqual(['subject' => $this->subject->uuid, 'section' => 'A', 'period' => 'month_1']);

    (new NotifySectionCompleted($messages[0]->uuid, $messages[0]->payload))->handle();

    $notification = DatabaseNotification::query()->sole();
    expect($notification->notifiable_id)->toBe($owner->id)
        ->and($notification->data)->toBe([
            'kind' => 'grades_section_completed',
            'subject' => 'Network Security',
            'stage' => '2',
            'section' => 'A',
            'teacher' => $this->teacher->name,
        ]);
});

it('refuses another teacher\'s subject, and a stopped teacher', function () {
    [$a] = $this->students;

    expect(fn () => saveMarks(Teacher::factory()->create(), $this->subject, [$a => Mark::of(50)]))
        ->toThrow(AuthorizationException::class);

    $this->teacher->forceFill(['is_active' => false])->save();
    expect(fn () => saveMarks($this->teacher, $this->subject, [$a => Mark::of(50)]))
        ->toThrow(AuthorizationException::class)
        ->and(GradeMark::query()->count())->toBe(0);
});

it('refuses students of another section or stage, writing nothing', function (string $who) {
    [$a] = $this->students;
    $stranger = $who === 'section' ? $this->otherSection->uuid : $this->otherStage->uuid;

    expect(fn () => saveMarks($this->teacher, $this->subject, [$a => Mark::of(50), $stranger => Mark::of(50)]))
        ->toThrow(DomainRuleViolation::class, 'Some students are not in this section.')
        ->and(GradeMark::query()->count())->toBe(0);
})->with(['section', 'stage']);

it('refuses a teacher while the window is closed, but lets the owner correct', function () {
    [$a] = $this->students;
    $owner = GradesFixtures::owner();
    app(ChangeEntryWindow::class)
        ->handle($owner, Period::Month1, WindowStatus::Closed);

    expect(fn () => saveMarks($this->teacher, $this->subject, [$a => Mark::of(50)]))
        ->toThrow(DomainRuleViolation::class, 'Mark entry is closed for this period.');

    saveMarks($owner, $this->subject, [$a => Mark::of(55)]);

    $audit = AuditEvent::query()->where('action', 'grades.marks_saved')->sole();
    expect(GradeMark::query()->sole()->entered_by_user_id)->toBe($owner->id)
        ->and($audit->actor_id)->toBe($owner->id)
        ->and($audit->metadata)->not->toHaveKey('teacher');
});

it('refuses a person who does not manage grades', function () {
    [$a] = $this->students;

    saveMarks(User::factory()->withTwoFactor()->create(), $this->subject, [$a => Mark::of(50)]);
})->throws(AuthorizationException::class);

it('refuses a section the stage does not have (the first stage has none)', function () {
    $first = Subject::factory()->create();
    $student = Student::factory()->create();

    expect(fn () => app(SaveMarks::class)->handle(SubjectFactory::teacherOf($first), $first, Section::B, Period::Month1, [$student->uuid => Mark::of(50)]))
        ->toThrow(DomainRuleViolation::class, 'This stage has no such section.')
        ->and(GradeMark::query()->count())->toBe(0);
});
