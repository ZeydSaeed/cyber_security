<?php

use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Application\Actions\SaveSubject;
use App\Modules\Grades\Database\Factories\SubjectFactory;
use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Grades\GradesFixtures;

/*
| A teacher per section (owner's request, 2026-11-18): in the teacher's
| form the owner chooses a subject and, in a divided stage, its sections.
| Each teacher then reaches only the students of their own sections.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    RateLimiter::clear('grades-teacher:minute:127.0.0.1');
    RateLimiter::clear('grades-teacher:hour:127.0.0.1');
    $this->owner = GradesFixtures::owner();
    $this->huda = Teacher::factory()->withCode('huda-code-11')->create(['name' => 'Huda Karim']);
    $this->omar = Teacher::factory()->withCode('omar-code-22')->create(['name' => 'Omar Saleh']);
    $this->networks = Subject::factory()->unassigned()->stage(Stage::Second)->create(['name' => 'Networks']);
    GradesFixtures::openWindow();
});

it('gives each section of a subject its own teacher from the teacher\'s form', function () {
    $this->actingAs($this->owner)
        ->put("/admin/teachers/{$this->huda->uuid}", ['subjects' => ['', "{$this->networks->uuid}:A"]])
        ->assertSessionHasNoErrors();
    $this->put("/admin/teachers/{$this->omar->uuid}", ['subjects' => ['', "{$this->networks->uuid}:B"]])
        ->assertSessionHasNoErrors();

    expect(SubjectFactory::teacherOf($this->networks, Section::A)?->id)->toBe($this->huda->id)
        ->and(SubjectFactory::teacherOf($this->networks, Section::B)?->id)->toBe($this->omar->id);

    $this->get('/admin/teachers')->assertInertia(fn (Assert $page) => $page
        ->where('subjects.0.teachers', ['A' => $this->huda->uuid, 'B' => $this->omar->uuid]));

    // Each section's change is recorded with its section.
    $audit = AuditEvent::query()->where('action', 'grades.subject_assigned')->latest('id')->first();
    expect($audit?->metadata)->toMatchArray(['section' => 'B'])
        ->and($audit?->changes)->toEqual(['teacher' => ['from' => null, 'to' => 'Omar Saleh']]);

    // The overview names each section's teacher.
    $this->get('/admin')->assertInertia(fn (Assert $page) => $page
        ->where('stages.1.subjects.0.teachers', ['A' => 'Huda Karim', 'B' => 'Omar Saleh']));
});

it('moves a section from its teacher, and leaves a section unticked without a teacher', function () {
    $this->actingAs($this->owner)->put("/admin/teachers/{$this->huda->uuid}", ['subjects' => ['', $this->networks->uuid]]);
    expect(SubjectFactory::teacherOf($this->networks, Section::B)?->id)->toBe($this->huda->id);

    // Omar takes section B: Huda keeps A.
    $this->put("/admin/teachers/{$this->omar->uuid}", ['subjects' => ['', "{$this->networks->uuid}:B"]]);
    expect(SubjectFactory::teacherOf($this->networks, Section::A)?->id)->toBe($this->huda->id)
        ->and(SubjectFactory::teacherOf($this->networks, Section::B)?->id)->toBe($this->omar->id);

    // Huda's form without section A: A has no teacher; Omar keeps B.
    $this->put("/admin/teachers/{$this->huda->uuid}", ['subjects' => ['']]);
    expect(SubjectFactory::teacherOf($this->networks, Section::A))->toBeNull()
        ->and(SubjectFactory::teacherOf($this->networks, Section::B)?->id)->toBe($this->omar->id);
});

it('refuses a section the stage does not have, and malformed choices', function () {
    $first = Subject::factory()->unassigned()->create(['name' => 'Ethics']);

    $this->actingAs($this->owner)->from('/admin/teachers')
        ->put("/admin/teachers/{$this->huda->uuid}", ['subjects' => ['', "{$first->uuid}:B"]])
        ->assertSessionHasErrors('subjects');
    $this->put("/admin/teachers/{$this->huda->uuid}", ['subjects' => ['', "{$this->networks->uuid}:C"]])
        ->assertSessionHasErrors('subjects.1');
    $this->put("/admin/teachers/{$this->huda->uuid}", ['subjects' => ['', '00000000-0000-4000-8000-000000000000:A']])
        ->assertSessionHasErrors('subjects');

    expect(SubjectFactory::teacherOf($first))->toBeNull()
        ->and(SubjectFactory::teacherOf($this->networks))->toBeNull();
});

it('shows a teacher only their own sections and students, and refuses the other section', function () {
    app(SaveSubject::class)->assign($this->owner, $this->networks, $this->huda, Section::A);
    app(SaveSubject::class)->assign($this->owner, $this->networks, $this->omar, Section::B);
    $a = Student::factory()->in(Stage::Second, Section::A)->named('Zainab Ali')->create();
    $b = Student::factory()->in(Stage::Second, Section::B)->named('Ahmed Omar')->create();

    $this->post('/teacher', ['code' => 'huda-code-11']);
    $this->get('/teacher/subjects')->assertInertia(fn (Assert $page) => $page
        ->has('subjects', 1)
        ->has('subjects.0.sections', 1)
        ->where('subjects.0.sections.0.section', 'A'));
    $this->get("/teacher/subjects/{$this->networks->uuid}/A")->assertInertia(fn (Assert $page) => $page
        ->has('students', 1)
        ->where('students.0.uuid', $a->uuid));
    $this->get("/teacher/subjects/{$this->networks->uuid}/B")->assertNotFound();
    $this->put("/teacher/subjects/{$this->networks->uuid}/B", ['marks' => [$b->uuid => '70']])->assertNotFound();

    expect(fn () => app(SaveMarks::class)->handle($this->huda, $this->networks, Section::B, Period::Month1, [$b->uuid => Mark::of(70)]))
        ->toThrow(AuthorizationException::class);
    expect(app(SaveMarks::class)->handle($this->omar, $this->networks, Section::B, Period::Month1, [$b->uuid => Mark::of(70)])['changed'])->toBe(1);
});

it('drops the teacher of a section the subject\'s new stage does not have', function () {
    app(SaveSubject::class)->assign($this->owner, $this->networks, $this->huda);

    app(SaveSubject::class)->changeStage($this->owner, $this->networks, Stage::First);

    expect(SubjectFactory::teacherOf($this->networks, Section::A)?->id)->toBe($this->huda->id)
        ->and(SubjectFactory::teacherOf($this->networks, Section::B))->toBeNull();
});
