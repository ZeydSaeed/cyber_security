<?php

use App\Modules\Grades\Domain\StudentAccess;
use App\Modules\Grades\Domain\TeacherAccess;
use App\Modules\Grades\Models\GradeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Grades\GradesFixtures;

/*
| The settings page in tabs (owner's request, 2026-11-19): /admin?tab=…
| opens one tab, anything else the list of tabs; the teachers' and the
| students' sign-in rules are saved from their own tabs, each keeping the
| other's values.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = GradesFixtures::owner();
});

it('opens a known tab and falls back to the list of tabs', function () {
    $this->actingAs($this->owner)->get('/admin?tab=students')->assertInertia(fn (Assert $page) => $page->where('tab', 'students'));
    $this->actingAs($this->owner)->get('/admin')->assertInertia(fn (Assert $page) => $page->where('tab', null));
    $this->actingAs($this->owner)->get('/admin?tab=nothing')->assertInertia(fn (Assert $page) => $page->where('tab', null));
    $this->actingAs($this->owner)->get('/admin?tab[]=window')->assertInertia(fn (Assert $page) => $page->where('tab', null));
});

it('saves the teachers\' rules and keeps the students\'', function () {
    $this->actingAs($this->owner)->put('/admin/access', [
        'student_attempts_per_minute' => 7,
        'student_attempts_per_hour' => 30,
        'student_access' => 'blocked',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->owner)->put('/admin/access', [
        'teacher_attempts_per_minute' => 3,
        'teacher_attempts_per_hour' => 12,
        'teacher_mode' => 'view_only',
        'remember_days' => 10,
    ])->assertSessionHasNoErrors();

    $settings = GradeSettings::current();
    expect($settings->teacher_attempts_per_minute)->toBe(3)
        ->and($settings->teacher_mode)->toBe(TeacherAccess::ViewOnly)
        ->and($settings->remember_days)->toBe(10)
        ->and($settings->student_attempts_per_minute)->toBe(7)
        ->and($settings->student_attempts_per_hour)->toBe(30)
        ->and($settings->student_access)->toBe(StudentAccess::Blocked);
});

it('checks the side it is given, and needs one side', function () {
    $this->actingAs($this->owner)->put('/admin/access', [
        'student_attempts_per_minute' => 9,
        'student_attempts_per_hour' => 3,
        'student_access' => 'allowed',
    ])->assertSessionHasErrors('student_attempts_per_hour');

    $this->actingAs($this->owner)->put('/admin/access', [])->assertSessionHasErrors('teacher_mode');
});
