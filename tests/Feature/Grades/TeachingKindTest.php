<?php

use App\Modules\Grades\Application\Actions\SaveSubject;
use App\Modules\Grades\Application\Actions\SaveTeacher;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\TeachingKind;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\SubjectTeacher;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Grades\GradesFixtures;

/*
| How a section is taught (owner's request, 2026-11-18): in the teacher's
| form, each section they teach is theoretical or practical, and may have
| an assistant, another teacher. The teachers' page shows both.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = GradesFixtures::owner();
    $this->huda = Teacher::factory()->create(['name' => 'Huda Karim']);
    $this->omar = Teacher::factory()->create(['name' => 'Omar Saleh']);
    $this->networks = Subject::factory()->unassigned()->stage(Stage::Second)->create(['name' => 'Networks']);
});

function row(Subject $subject, Section $section): ?SubjectTeacher
{
    return SubjectTeacher::query()->where('subject_id', $subject->id)->where('section', $section->value)->first();
}

it('saves the type and the assistant of each section from the teacher\'s form, and shows them', function () {
    $a = "{$this->networks->uuid}:A";
    $b = "{$this->networks->uuid}:B";

    $this->actingAs($this->owner)->put("/admin/teachers/{$this->huda->uuid}", [
        'subjects' => ['', $a, $b],
        'details' => [
            $a => ['kind' => 'practical', 'assistant' => $this->omar->uuid],
            $b => ['kind' => 'theory', 'assistant' => ''],
        ],
    ])->assertSessionHasNoErrors();

    expect(row($this->networks, Section::A)?->kind)->toBe(TeachingKind::Practical)
        ->and(row($this->networks, Section::A)?->assistant_id)->toBe($this->omar->id)
        ->and(row($this->networks, Section::B)?->kind)->toBe(TeachingKind::Theory)
        ->and(row($this->networks, Section::B)?->assistant_id)->toBeNull();

    $this->get('/admin/teachers')->assertInertia(fn (Assert $page) => $page
        ->where('subjects.0.kinds', ['A' => 'practical', 'B' => 'theory'])
        ->where('subjects.0.assistants', ['A' => $this->omar->uuid, 'B' => null]));

    $audit = AuditEvent::query()->where('action', 'grades.subject_teaching_changed')->orderBy('id')->first();
    expect($audit?->metadata)->toMatchArray(['section' => 'A'])
        ->and($audit?->changes)->toEqual([
            'kind' => ['from' => null, 'to' => 'practical'],
            'assistant' => ['from' => null, 'to' => 'Omar Saleh'],
        ]);

    // Saved again unchanged: nothing more recorded. Cleared: both go.
    $this->put("/admin/teachers/{$this->huda->uuid}", [
        'subjects' => ['', $a, $b],
        'details' => [$a => ['kind' => 'practical', 'assistant' => $this->omar->uuid], $b => ['kind' => 'theory', 'assistant' => '']],
    ]);
    expect(AuditEvent::query()->where('action', 'grades.subject_teaching_changed')->count())->toBe(2);

    $this->put("/admin/teachers/{$this->huda->uuid}", [
        'subjects' => ['', $a],
        'details' => [$a => ['kind' => '', 'assistant' => '']],
    ]);
    expect(row($this->networks, Section::A)?->kind)->toBeNull()
        ->and(row($this->networks, Section::A)?->assistant_id)->toBeNull()
        ->and(row($this->networks, Section::B))->toBeNull();
});

it('fills the print slip: sections taught alike share a block, with their type and assistant', function () {
    $save = app(SaveSubject::class);
    $save->assign($this->owner, $this->networks, $this->huda);
    $save->describe($this->owner, $this->networks, Section::A, TeachingKind::Practical, $this->omar);
    $first = Subject::factory()->unassigned()->create(['name' => 'Ethics']);
    $save->assign($this->owner, $first, $this->huda);

    $this->actingAs($this->owner)->get('/admin/teachers/print')->assertInertia(fn (Assert $page) => $page
        ->where('teachers.0.name', 'Huda Karim')
        ->has('teachers.0.subjects', 3)
        ->where('teachers.0.subjects.0.name', 'Ethics')
        ->where('teachers.0.subjects.0.kind', null)
        ->where('teachers.0.subjects.0.assistant', null)
        ->where('teachers.0.subjects.1.sections', ['A'])
        ->where('teachers.0.subjects.1.kind', 'practical')
        ->where('teachers.0.subjects.1.assistant', 'Omar Saleh')
        ->where('teachers.0.subjects.2.sections', ['B'])
        ->where('teachers.0.subjects.2.kind', null));

    // Both sections alike again: one block.
    $save->describe($this->owner, $this->networks, Section::B, TeachingKind::Practical, $this->omar);
    $this->get('/admin/teachers/print')->assertInertia(fn (Assert $page) => $page
        ->has('teachers.0.subjects', 2)
        ->where('teachers.0.subjects.1.sections', ['A', 'B']));
});

it('refuses a teacher as their own assistant, an unknown type and an unknown assistant', function () {
    $a = "{$this->networks->uuid}:A";
    $this->actingAs($this->owner)->from('/admin/teachers');

    $this->put("/admin/teachers/{$this->huda->uuid}", ['subjects' => ['', $a], 'details' => [$a => ['assistant' => $this->huda->uuid]]])
        ->assertSessionHasErrors('subjects');
    $this->put("/admin/teachers/{$this->huda->uuid}", ['subjects' => ['', $a], 'details' => [$a => ['kind' => 'lab']]])
        ->assertSessionHasErrors("details.{$a}.kind");
    $this->put("/admin/teachers/{$this->huda->uuid}", ['subjects' => ['', $a], 'details' => [$a => ['assistant' => '00000000-0000-4000-8000-000000000000']]])
        ->assertSessionHasErrors("details.{$a}.assistant");

    // The whole form is one transaction: nothing was given to Huda.
    expect(row($this->networks, Section::A))->toBeNull();
});

it('ignores the details of a section the teacher does not teach', function () {
    app(SaveSubject::class)->assign($this->owner, $this->networks, $this->omar, Section::B);

    $this->actingAs($this->owner)->put("/admin/teachers/{$this->huda->uuid}", [
        'details' => ["{$this->networks->uuid}:B" => ['kind' => 'practical', 'assistant' => $this->huda->uuid]],
    ])->assertSessionHasNoErrors();

    expect(row($this->networks, Section::B)?->kind)->toBeNull()
        ->and(row($this->networks, Section::B)?->assistant_id)->toBeNull();
});

it('starts a new teacher of a section without the old type and assistant', function () {
    $save = app(SaveSubject::class);
    $save->assign($this->owner, $this->networks, $this->huda, Section::A);
    $save->describe($this->owner, $this->networks, Section::A, TeachingKind::Practical, $this->omar);

    // Omar takes the section his assistant role was in.
    $save->assign($this->owner, $this->networks, $this->omar, Section::A);

    expect(row($this->networks, Section::A)?->teacher_id)->toBe($this->omar->id)
        ->and(row($this->networks, Section::A)?->kind)->toBeNull()
        ->and(row($this->networks, Section::A)?->assistant_id)->toBeNull();
});

it('leaves a section without its assistant when the assistant is deleted', function () {
    $save = app(SaveSubject::class);
    $save->assign($this->owner, $this->networks, $this->huda, Section::A);
    $save->describe($this->owner, $this->networks, Section::A, TeachingKind::Theory, $this->omar);

    app(SaveTeacher::class)->delete($this->owner, $this->omar);

    expect(row($this->networks, Section::A)?->assistant_id)->toBeNull()
        ->and(row($this->networks, Section::A)?->kind)->toBe(TeachingKind::Theory);
});
