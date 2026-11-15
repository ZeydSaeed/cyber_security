<?php

namespace Database\Seeders;

use App\Access\Role;
use App\Models\User;
use App\Modules\Grades\Application\StudentCodes;
use App\Modules\Grades\Application\TeacherCodes;
use App\Modules\Grades\Domain\NameKey;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;
use App\Notifications\RoleChanged;
use App\Platform\Access\RoleAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;

/**
 * One signed-up user with a confirmed authenticator app for the browser
 * tests (tests/browser, ADR-0024), and a small Grades department (a
 * teacher, subjects and students, ADR-0034). Runs only on the browser tests' own
 * SQLite file, never on a real database. The password and authenticator
 * secret are generated per run and written for Playwright to
 * storage/app/private/browser/user.json.
 */
class BrowserTestSeeder extends Seeder
{
    /** The browser tests' teacher signs in with this code (Grades, ADR-0034). */
    public const TEACHER_CODE = 'browser-teacher-2026';

    public function run(Google2FA $google2fa): void
    {
        $connection = (array) config('database.connections.'.config('database.default'));

        if (app()->environment('production') || ($connection['driver'] ?? '') !== 'sqlite' || ! str_ends_with((string) ($connection['database'] ?? ''), 'browser.sqlite')) {
            throw new RuntimeException('BrowserTestSeeder runs only on database/browser.sqlite.');
        }

        $password = bin2hex(random_bytes(12)).'-Aa1';
        $secret = $google2fa->generateSecretKey(32);

        $browser = User::factory()->create([
            'name' => 'Browser Test',
            'email' => 'browser@example.test',
            'password' => $password,
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt((string) json_encode(['browser-recovery-code'])),
            'two_factor_confirmed_at' => now(),
        ]);
        RoleAssignment::query()->forceCreate(['user_id' => $browser->id, 'role' => Role::Administrator->value]);

        // Two unread notifications, for the notification tests.
        $browser->notify(new RoleChanged('role_granted', Role::Administrator->value, 'Setup'));
        $browser->notify(new RoleChanged('role_granted', Role::Auditor->value, 'Setup'));

        // Enough people for two pages of the user list, with mixed roles.
        foreach (range(1, 40) as $i) {
            $user = User::factory()->create([
                'name' => sprintf('Person %02d', $i),
                'email' => sprintf('person-%02d@example.test', $i),
                'two_factor_confirmed_at' => $i % 4 === 0 ? null : now(),
            ]);

            if ($i % 5 === 0) {
                RoleAssignment::query()->forceCreate(['user_id' => $user->id, 'role' => Role::Auditor->value]);
            }
        }

        // Grades (ADR-0034): a teacher with a known code, a subject in each
        // stage, six students in the first stage (no sections) and six in
        // each section of the second, each with a code, and the first month
        // open for entry (teacher.spec.ts, student.spec.ts).
        GradePeriod::for(Period::Month1)->forceFill(['status' => WindowStatus::Open, 'opened_at' => now(), 'closed_at' => null])->save();
        $teacher = new Teacher;
        $teacher->forceFill(['name' => 'Teacher Test', 'code_digest' => app(TeacherCodes::class)->digest(self::TEACHER_CODE)])->save();
        foreach ([Stage::First->value => 'Network Security', Stage::Second->value => 'Cryptography'] as $stage => $name) {
            $subject = new Subject;
            $subject->forceFill(['stage' => $stage, 'name' => $name, 'teacher_id' => $teacher->id, 'sort_order' => 1])->save();
        }
        $codes = app(StudentCodes::class);
        $studentCode = null;
        foreach (Stage::cases() as $stage) {
            foreach ($stage->sections() as $section) {
                foreach (range(1, 6) as $n) {
                    $name = $stage->isDivided() ? "Student {$stage->value}{$section->value}{$n}" : "Student {$stage->value}-{$n}";
                    $code = $codes->generate();
                    $studentCode ??= $code;
                    $student = new Student;
                    $student->forceFill([
                        'full_name' => $name, 'name_key' => NameKey::of($name), 'stage' => $stage, 'section' => $section,
                        'code_digest' => $codes->digest($code), 'code_encrypted' => $codes->encrypt($code),
                    ])->save();
                }
            }
        }

        File::ensureDirectoryExists(storage_path('app/private/browser'));
        File::put(storage_path('app/private/browser/user.json'), (string) json_encode([
            'email' => 'browser@example.test',
            'password' => $password,
            'secret' => $secret,
            // Signed up without a second factor yet: is sent to set one up.
            'setup_email' => 'setup@example.test',
            'teacher_code' => self::TEACHER_CODE,
            // "Student 1-1", the first stage's first student.
            'student_code' => $studentCode,
        ]));

        User::factory()->create([
            'name' => 'Setup Test',
            'email' => 'setup@example.test',
            'password' => $password,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);
    }
}
