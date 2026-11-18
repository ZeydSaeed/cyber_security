<?php

use App\Models\User;
use App\Modules\Grades\Application\Actions\ChangeEntryWindow;
use App\Modules\Grades\Application\Actions\SaveSubject;
use App\Modules\Grades\Application\Actions\SaveTeacher;
use App\Modules\Grades\Application\TeacherCodes;
use App\Modules\Grades\Database\Factories\SubjectFactory;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditEvent;
use App\Platform\Exceptions\DomainRuleViolation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Grades\GradesFixtures;

/*
| The owner's set-up of the department (ADR-0034): teachers and their codes,
| subjects, and the entry window. Only people who manage grades; each change
| audited once; repeating a change changes nothing.
*/

uses(RefreshDatabase::class);

describe('teachers', function () {
    it('keeps only a digest of the code, never the code, and finds the teacher by it', function () {
        $teacher = app(SaveTeacher::class)->create(GradesFixtures::owner(), '  Sara   Ahmed ', 'blue-river-42');
        $codes = app(TeacherCodes::class);

        expect($teacher->name)->toBe('Sara Ahmed')
            ->and($teacher->code_digest)->toBe($codes->digest('blue-river-42'))
            ->and($teacher->code_digest)->not->toContain('blue-river-42')
            ->and(Teacher::query()->where('code_digest', $codes->digest(' blue-river-42 '))->value('id'))->toBe($teacher->id)
            ->and($teacher->toArray())->not->toHaveKey('code_digest');

        $audit = AuditEvent::query()->where('action', 'grades.teacher_created')->sole();
        expect(json_encode($audit->toArray()))->not->toContain('blue-river-42')
            ->and($audit->subject_type)->toBe(Teacher::MORPH);
    });

    it('reads Arabic-Indic digits in a code as the same digits', function () {
        $codes = app(TeacherCodes::class);

        expect($codes->digest("\u{0661}\u{0662}\u{0663}\u{0664}\u{0665}\u{0666}\u{0667}\u{0668}"))->toBe($codes->digest('12345678'));
    });

    it('refuses a short code, a code with spaces, and another teacher\'s code', function (string $code, string $message) {
        $owner = GradesFixtures::owner();
        app(SaveTeacher::class)->create($owner, 'First Teacher', 'taken-code-1');

        expect(fn () => app(SaveTeacher::class)->create($owner, 'Second Teacher', $code))
            ->toThrow(DomainRuleViolation::class, $message);
    })->with([
        'too short' => ['short7', 'The code must be between 8 and 64 characters.'],
        'spaces' => ['two words', 'The code may not contain spaces.'],
        'taken' => ['taken-code-1', 'Another teacher already has this code.'],
    ]);

    it('ends sessions by raising the lock version when the code changes or the teacher is stopped', function () {
        $owner = GradesFixtures::owner();
        $teacher = app(SaveTeacher::class)->create($owner, 'Teacher', 'first-code-1');

        $teacher = app(SaveTeacher::class)->changeCode($owner, $teacher, 'second-code-2');
        expect($teacher->lock_version)->toBe(2);

        // The same code again: nothing changes, nothing recorded.
        $teacher = app(SaveTeacher::class)->changeCode($owner, $teacher, 'second-code-2');
        $teacher = app(SaveTeacher::class)->setActive($owner, $teacher, false);
        $teacher = app(SaveTeacher::class)->setActive($owner, $teacher, false);

        expect($teacher->lock_version)->toBe(3)
            ->and($teacher->is_active)->toBeFalse()
            ->and(AuditEvent::query()->where('action', 'grades.teacher_code_changed')->count())->toBe(1)
            ->and(AuditEvent::query()->where('action', 'grades.teacher_deactivated')->count())->toBe(1);
    });

    it('is the business of those who manage grades only', function () {
        $someone = User::factory()->withTwoFactor()->create();

        app(SaveTeacher::class)->create($someone, 'Teacher', 'some-code-1');
    })->throws(AuthorizationException::class);
});

describe('subjects', function () {
    it('creates subjects in order within their stage, and refuses a repeated name', function () {
        $owner = GradesFixtures::owner();
        $teacher = Teacher::factory()->create();
        $first = app(SaveSubject::class)->create($owner, Stage::First, 'Network Security', $teacher);
        $second = app(SaveSubject::class)->create($owner, Stage::First, 'Cryptography');
        // The same name in the other stage is another subject.
        app(SaveSubject::class)->create($owner, Stage::Second, 'Network Security');

        expect([$first->sort_order, $second->sort_order])->toBe([1, 2])
            ->and(SubjectFactory::teacherOf($second)?->id)->toBeNull()
            ->and(fn () => app(SaveSubject::class)->create($owner, Stage::First, 'Network Security'))
            ->toThrow(DomainRuleViolation::class, 'This stage already has a subject with this name.');
    });

    it('assigns and changes the teacher, recording who was replaced', function () {
        $owner = GradesFixtures::owner();
        $subject = Subject::factory()->create();
        $old = SubjectFactory::teacherOf($subject);
        $new = Teacher::factory()->create(['name' => 'New Teacher']);

        app(SaveSubject::class)->assign($owner, $subject, $new);
        app(SaveSubject::class)->assign($owner, $subject, $new);

        $audit = AuditEvent::query()->where('action', 'grades.subject_assigned')->sole();
        expect(SubjectFactory::teacherOf($subject)?->id)->toBe($new->id)
            ->and($audit->changes)->toEqual(['teacher' => ['from' => $old?->name, 'to' => 'New Teacher']]);
    });
});

describe('entry window', function () {
    it('starts closed, opens, closes and reopens, each move recorded once', function () {
        $owner = GradesFixtures::owner();
        $window = app(ChangeEntryWindow::class);

        expect(GradePeriod::for(Period::Month1)->status)->toBe(WindowStatus::Closed);

        $window->handle($owner, Period::Month1, WindowStatus::Open);
        $window->handle($owner, Period::Month1, WindowStatus::Open);
        $closed = $window->handle($owner, Period::Month1, WindowStatus::Closed);

        expect($closed->status)->toBe(WindowStatus::Closed)
            ->and($closed->closed_at)->not->toBeNull();

        $reopened = $window->handle($owner, Period::Month1, WindowStatus::Open);

        expect($reopened->closed_at)->toBeNull()
            ->and(AuditEvent::query()->where('action', 'grades.window_opened')->count())->toBe(2)
            ->and(AuditEvent::query()->where('action', 'grades.window_closed')->count())->toBe(1)
            ->and(GradePeriod::query()->count())->toBe(1);
    });
});
