<?php

use App\Modules\Grades\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Grades\GradesFixtures;

/*
| SQL metacharacters in a teacher's name are data, never SQL (bound
| parameters through Eloquent). This is the evidence behind the reviewed ZAP
| exception for rule 40018 on POST /admin/teachers (.zap/high-allowlist.tsv):
| ZAP sent two boolean payloads with the same code, and the second page
| differed only because two teachers never share a code.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = GradesFixtures::owner();
});

dataset('sql payloads', [
    "ZAP' AND '1'='1' --",
    "ZAP' AND '1'='2' --",
    "x'); DROP TABLE grade_teachers; --",
    "Robert' OR 1=1 --",
]);

it('stores a name with SQL metacharacters exactly as typed', function (string $name) {
    $this->actingAs($this->owner)->from('/admin/teachers')
        ->post('/admin/teachers', ['name' => $name, 'code' => 'quiet-lake-77'])
        ->assertRedirect('/admin/teachers')
        ->assertSessionHasNoErrors();

    expect(Teacher::query()->pluck('name')->all())->toBe([$name]);
})->with('sql payloads');

it('keeps the name as data when renaming', function (string $name) {
    $this->actingAs($this->owner)->post('/admin/teachers', ['name' => 'Huda Karim', 'code' => 'quiet-lake-77']);
    $teacher = Teacher::query()->sole();

    $this->put("/admin/teachers/{$teacher->uuid}", ['name' => $name])->assertSessionHasNoErrors();

    expect($teacher->fresh()->name)->toBe($name)
        ->and(Teacher::query()->count())->toBe(1);
})->with('sql payloads');

it('answers the boolean pair ZAP sent by the code rule, not by the condition', function () {
    $this->actingAs($this->owner)->from('/admin/teachers');

    // Same code twice (as ZAP sent it): the second is refused for the code.
    $this->post('/admin/teachers', ['name' => "ZAP' AND '1'='1' --", 'code' => 'quiet-lake-77'])->assertSessionHasNoErrors();
    $this->post('/admin/teachers', ['name' => "ZAP' AND '1'='2' --", 'code' => 'quiet-lake-77'])->assertSessionHasErrors('code');

    // The "false" condition with another code is accepted like any name.
    $this->post('/admin/teachers', ['name' => "ZAP' AND '1'='2' --", 'code' => 'other-river-42'])->assertSessionHasNoErrors();

    expect(Teacher::query()->orderBy('id')->pluck('name')->all())
        ->toBe(["ZAP' AND '1'='1' --", "ZAP' AND '1'='2' --"]);
});
