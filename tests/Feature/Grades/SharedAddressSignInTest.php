<?php

use App\Modules\Grades\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

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
