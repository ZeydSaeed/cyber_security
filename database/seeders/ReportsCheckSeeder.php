<?php

namespace Database\Seeders;

use App\Access\Role;
use App\Models\User;
use App\Modules\Grades\Application\Actions\ApproveSection;
use App\Modules\Grades\Application\Actions\ChangeEntryWindow;
use App\Modules\Grades\Application\Actions\ManageAppeals;
use App\Modules\Grades\Application\Actions\ManageResults;
use App\Modules\Grades\Application\Actions\PublishResults;
use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Application\Actions\SaveStudent;
use App\Modules\Grades\Application\Actions\SaveSubject;
use App\Modules\Grades\Application\Actions\SaveTeacher;
use App\Modules\Grades\Domain\GradeBands;
use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\ResultDisplay;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Access\RoleAssignment;
use App\Platform\Audit\AuditLogger;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The academic reports' check data (owner's request, 2026-11-20): a small
 * department whose every figure is worked out by hand below, made through
 * the same actions the pages use (so the audit trail is real). The tests
 * (AcademicReportsTest) compare the reports with these figures; any
 * difference is a fault in the reports.
 *
 * Settings: pass 50% of each subject's maximum, general exemption at 90%,
 * a student flagged from one absence.
 *
 * First stage (one group, "1A"): Mathematics /100 (pass 50) and Physics
 * /50 (pass 25), both taught by Teacher One.
 *
 *   student   Maths  Physics  total/150  %       result
 *   Amal       95     48       143       95.33   passed, exempt (>= 90)
 *   Basim      60     30        90       60.00   passed (Maths 58, then corrected to 60)
 *   Dalia      40     absent    40       26.67   failed two subjects, one absence
 *   Hadi       72     —          —         —     incomplete (no Physics mark)
 *
 *   Maths: 4 entered, 3 passed (75.0%), mean (95+60+40+72)/4 = 66.75 -> 66.8
 *   Physics: 3 entered, 1 absent, 2 passed (66.7%), mean of present
 *   (96+60)/2 = 78.0; absences 33.3%. Stage mean (66.8+78.0)/2 = 72.4.
 *
 * Second stage: Networks /100, Teacher Two in section A, nobody in B.
 *
 *   2A: Kamil 80 (passed), Layla 45 (failed)        pass rate 50.0, mean 62.5
 *   2B: Maha 55 (passed, entered by the owner), Nabil — (incomplete)
 *
 * Teachers: One approves Maths, not Physics (state "started"); Two
 * approves Networks 2A ("approved"); Three teaches nothing ("free").
 * Sign-ins: One 3, Two 1, Three 0; Amal 2 (two views), Basim 1 (one view).
 * Published: 1A and 2A. Appeals: Dalia on Physics (waiting), Layla on
 * Networks (accepted by the owner).
 *
 * Totals: 8 students, 6 complete, 4 passed, 2 failed, 1 exempt,
 * 2 incomplete; pass rate 66.7; mean (95.33+60+26.67+80+45+55)/6 = 60.3.
 */
class ReportsCheckSeeder extends Seeder
{
    public const PASSWORD = 'reports-check-Aa1-not-a-secret';

    public function run(): void
    {
        $connection = (array) config('database.connections.'.config('database.default'));
        $database = (string) ($connection['database'] ?? '');
        if (app()->environment('production') || ($connection['driver'] ?? '') !== 'sqlite' || ($database !== ':memory:' && ! str_ends_with($database, 'reports-check.sqlite'))) {
            throw new RuntimeException('ReportsCheckSeeder runs only on database/reports-check.sqlite or an in-memory test database.');
        }

        $owner = User::factory()->create(['name' => 'Reports Owner', 'email' => 'owner@reports-check.test', 'password' => self::PASSWORD]);
        RoleAssignment::query()->forceCreate(['user_id' => $owner->id, 'role' => Role::Administrator->value]);

        self::build($owner);
    }

    /**
     * Makes the department described above; returns its people by name.
     *
     * @return array{teachers: array<string, Teacher>, students: array<string, Student>}
     */
    public static function build(User $owner): array
    {
        $saveTeacher = app(SaveTeacher::class);
        $saveSubject = app(SaveSubject::class);
        $saveStudent = app(SaveStudent::class);
        $marks = app(SaveMarks::class);
        $audit = app(AuditLogger::class);

        app(ManageResults::class)->settings($owner, 50, null, 90, GradeBands::DEFAULTS, ResultDisplay::OPTIONS, ResultDisplay::TONED, 7 * 1440, 1);

        $one = $saveTeacher->create($owner, 'Teacher One');
        $two = $saveTeacher->create($owner, 'Teacher Two');
        $three = $saveTeacher->create($owner, 'Teacher Three');

        $maths = $saveSubject->create($owner, Stage::First, 'Mathematics');
        $physics = $saveSubject->create($owner, Stage::First, 'Physics');
        $saveSubject->changeScale($owner, $physics, 50, null, null);
        $saveSubject->assign($owner, $maths, $one, Section::A);
        $saveSubject->assign($owner, $physics, $one, Section::A);
        $networks = $saveSubject->create($owner, Stage::Second, 'Networks');
        $saveSubject->assign($owner, $networks, $two, Section::A);

        $amal = $saveStudent->create($owner, 'Amal', Stage::First, Section::A);
        $basim = $saveStudent->create($owner, 'Basim', Stage::First, Section::A);
        $dalia = $saveStudent->create($owner, 'Dalia', Stage::First, Section::A);
        $hadi = $saveStudent->create($owner, 'Hadi', Stage::First, Section::A);
        $kamil = $saveStudent->create($owner, 'Kamil', Stage::Second, Section::A);
        $layla = $saveStudent->create($owner, 'Layla', Stage::Second, Section::A);
        $maha = $saveStudent->create($owner, 'Maha', Stage::Second, Section::B);
        $nabil = $saveStudent->create($owner, 'Nabil', Stage::Second, Section::B);

        app(ChangeEntryWindow::class)->handle($owner, Period::Month1, WindowStatus::Open);

        $marks->handle($one, $maths, Section::A, Period::Month1, [$amal->uuid => Mark::of(95), $basim->uuid => Mark::of(58), $dalia->uuid => Mark::of(40), $hadi->uuid => Mark::of(72)]);
        // A correction: one more change in the trail than marks in the sheet.
        $marks->handle($one, $maths, Section::A, Period::Month1, [$basim->uuid => Mark::of(60)]);
        $marks->handle($one, $physics, Section::A, Period::Month1, [$amal->uuid => Mark::of(48), $basim->uuid => Mark::of(30), $dalia->uuid => Mark::absent()]);
        $marks->handle($two, $networks, Section::A, Period::Month1, [$kamil->uuid => Mark::of(80), $layla->uuid => Mark::of(45)]);
        // Nobody teaches Networks in 2B: the owner enters it.
        $marks->handle($owner, $networks, Section::B, Period::Month1, [$maha->uuid => Mark::of(55)]);

        app(ApproveSection::class)->approve($one, $maths, Section::A, Period::Month1);
        app(ApproveSection::class)->approve($two, $networks, Section::A, Period::Month1);

        app(ChangeEntryWindow::class)->handle($owner, Period::Month1, WindowStatus::Closed);
        app(PublishResults::class)->sections($owner, Period::Month1, [[Stage::First, Section::A], [Stage::Second, Section::A]]);

        // Sign-ins and views, as the sign-in pages and ResultViews record them.
        foreach ([[$one, 3], [$two, 1]] as [$teacher, $count]) {
            $teacher->forceFill(['login_count' => $count, 'last_login_at' => now()])->save();
            foreach (range(1, $count) as $_) {
                $audit->record('grades.teacher_login', $teacher, ['teacher_name' => $teacher->name], withoutActor: true);
            }
        }
        foreach ([[$amal, 2], [$basim, 1]] as [$student, $count]) {
            $student->forceFill(['login_count' => $count, 'last_login_at' => now()])->save();
            foreach (range(1, $count) as $_) {
                $audit->record('grades.student_login', $student, withoutActor: true);
                $audit->record('grades.student_result_viewed', $student, withoutActor: true);
            }
        }

        $appeals = app(ManageAppeals::class);
        $appeals->submit($dalia, $physics, Period::Month1, 'I was ill on the day of the exam and have a report.');
        $accepted = $appeals->submit($layla, $networks, Period::Month1, 'Question four was marked against the wrong answer.');
        $appeals->decide($owner, $accepted, true, 'Re-marked: question four was right.');

        return [
            'teachers' => ['Teacher One' => $one, 'Teacher Two' => $two, 'Teacher Three' => $three],
            'students' => ['Amal' => $amal, 'Basim' => $basim, 'Dalia' => $dalia, 'Hadi' => $hadi, 'Kamil' => $kamil, 'Layla' => $layla, 'Maha' => $maha, 'Nabil' => $nabil],
        ];
    }
}
