<?php

namespace App\Modules\Grades\Application\Console;

use App\Models\User;
use App\Modules\Grades\Application\Actions\ApproveSection;
use App\Modules\Grades\Application\Actions\ChangeEntryWindow;
use App\Modules\Grades\Application\Actions\ManageAccess;
use App\Modules\Grades\Application\Actions\ManageAppeals;
use App\Modules\Grades\Application\Actions\ManageResults;
use App\Modules\Grades\Application\Actions\PublishResults;
use App\Modules\Grades\Application\Actions\RequestEdit;
use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Application\Queries\GradesBoard;
use App\Modules\Grades\Application\Queries\ResultSheets;
use App\Modules\Grades\Application\SectionAccess;
use App\Modules\Grades\Domain\AppealScope;
use App\Modules\Grades\Domain\AppealStatus;
use App\Modules\Grades\Domain\Behaviour;
use App\Modules\Grades\Domain\GradeBands;
use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\ResultDisplay;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\StudentAccess;
use App\Modules\Grades\Domain\TeacherAccess;
use App\Modules\Grades\Domain\TeacherResults;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Http\GradesAdminController;
use App\Modules\Grades\Models\GradeAppeal;
use App\Modules\Grades\Models\GradeEditRequest;
use App\Modules\Grades\Models\GradeMark;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\GradeSettings;
use App\Modules\Grades\Models\SectionApproval;
use App\Modules\Grades\Models\SectionPublication;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\SubjectTeacher;
use App\Modules\Grades\Models\Teacher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * The whole data flow of the Grades module, tried setting by setting
 * (owner's request, 2026-11-20). On the department as it stands (its
 * teachers, subjects and students are never changed):
 *
 * A. Fill: every student gets a mark in every subject of their stage,
 *    entered by the section's own teacher through SaveMarks; teachers
 *    approve, ask for changes, and the owner answers; behaviour and notes.
 * B. Matrix, rolled back afterwards: every value of every setting that
 *    decides what someone may do or see (pass and exemption rules, grade
 *    bands, what the student sees, what teachers see and when, the
 *    department's, a teacher's and a section's access, mark entry and
 *    publication, students' sign-in, appeals: on/off, who, from, until,
 *    for how long, who answers) is set through the module's own actions,
 *    and the outcome compared with a rule worked out here independently.
 *    The control panel's figures and the academic reports are recounted
 *    from the raw tables after each stage.
 * C. Final state: results published, appeals open; some appeals answered
 *    by teachers, some by the department, some waiting.
 *
 * Everything runs in one transaction, rolled back unless --keep is given
 * (and nothing failed). Users and passwords are never touched; the
 * owner's actions are made as the first user allowed grades.manage.
 */
class FlowCheck extends Command
{
    protected $signature = 'grades:flow-check
        {--keep : Commit the filled marks, appeals and final state (never in production)}
        {--seed=20261120 : Seed of the made-up marks}
        {--report= : Write the summary as JSON to this path}';

    protected $description = 'Fill marks and appeals, then try every setting combination of the Grades module and check each outcome';

    private User $owner;

    /** @var array<string, array{ok: int, failed: int}> */
    private array $tally = [];

    /** @var list<array{0: string, 1: string, 2: string, 3: string}> */
    private array $failures = [];

    /** @var array<string, int> */
    private array $facts = [];

    public function handle(): int
    {
        if ($this->option('keep') && app()->environment('production')) {
            $this->error('grades:flow-check --keep never runs in production.');

            return self::FAILURE;
        }

        $owner = null;
        foreach (User::query()->orderBy('id')->pluck('id') as $id) {
            $user = User::query()->whereKey($id)->firstOrFail();
            if ($user->can('grades.manage')) {
                $owner = $user;

                break;
            }
        }
        if (! $owner instanceof User) {
            $this->error('No user may manage the grades.');

            return self::FAILURE;
        }
        $this->owner = $owner;
        mt_srand((int) $this->option('seed'));

        $before = $this->untouchable();
        DB::beginTransaction();
        try {
            $this->line('A. Filling marks, approvals, requests and notes…');
            $this->fill();
            $this->checkCards('after filling');

            // B runs inside a savepoint: none of it stays.
            DB::beginTransaction();
            try {
                foreach ([
                    'results' => 'calculation',
                    'student view' => 'studentView',
                    'teacher view' => 'teacherView',
                    'access' => 'access',
                    'student sign-in' => 'studentSignIn',
                    'entry and publication' => 'windowAndPublication',
                    'appeals' => 'appeals',
                    'appeal answers' => 'appealAnswers',
                ] as $label => $method) {
                    $this->line("B. {$label}…");
                    $this->{$method}();
                    Carbon::setTestNow();
                }
            } finally {
                Carbon::setTestNow();
                DB::rollBack();
            }

            $this->line('C. Final state: published, appeals sent and answered…');
            $this->finalState();
            $this->checkCards('final state');
            $this->checkReports();
            $this->check('untouched', 'users, teachers and subjects', $before, $this->untouchable());
        } catch (Throwable $e) {
            Carbon::setTestNow();
            DB::rollBack();

            throw $e;
        }

        $failed = count($this->failures);
        $kept = $this->option('keep') && $failed === 0;
        $kept ? DB::commit() : DB::rollBack();

        $this->report($kept);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    // ------------------------------------------------------------------
    // A. Fill
    // ------------------------------------------------------------------

    private function fill(): void
    {
        $window = GradePeriod::for(Period::Month1);
        if ($window->anyPublished()) {
            app(PublishResults::class)->handle($this->owner, Period::Month1, false);
        }
        app(ChangeEntryWindow::class)->handle($this->owner, Period::Month1, WindowStatus::Open);

        $rows = $this->rows();
        foreach ($rows as $row) {
            $subject = $row->subject;
            $students = $this->studentsOf($subject->stage, $row->section);
            if ($students === []) {
                continue;
            }

            $marks = [];
            foreach ($students as $uuid) {
                $marks[$uuid] = $this->madeUpMark($subject->max_mark);
            }

            $teacher = $row->teacher;
            $byTeacher = $teacher->is_active;
            try {
                app(SaveMarks::class)->handle($byTeacher ? $teacher : $this->owner, $subject, $row->section, Period::Month1, $marks);
            } catch (Throwable) {
                // The teacher may not write here (their access): the owner enters it.
                $byTeacher = false;
                app(SaveMarks::class)->handle($this->owner, $subject, $row->section, Period::Month1, $marks);
            }
            $this->facts[$byTeacher ? 'sheets entered by their teacher' : 'sheets entered by the owner']
                = ($this->facts[$byTeacher ? 'sheets entered by their teacher' : 'sheets entered by the owner'] ?? 0) + 1;
        }

        // Every student has a mark in every subject of their stage.
        $expected = 0;
        foreach (Student::query()->get(['id', 'stage']) as $student) {
            $expected += Subject::query()->where('stage', $student->stage->value)->count();
        }
        $this->check('fill', 'every student has every mark', $expected, GradeMark::query()->where('period', Period::Month1->value)->count());
        $this->facts['marks'] = $expected;

        // Most sections approved by their teacher.
        $approved = [];
        foreach ($rows as $row) {
            if ($this->studentsOf($row->subject->stage, $row->section) === [] || mt_rand(1, 100) > 85) {
                continue;
            }
            try {
                app(ApproveSection::class)->approve($row->teacher, $row->subject, $row->section, Period::Month1);
                $approved[] = $row;
            } catch (Throwable $e) {
                $this->check('fill', "approve {$row->subject->name} {$row->section->value}", 'approved', class_basename($e));
            }
        }
        $this->facts['approved sections'] = count($approved);

        // Four teachers ask to change approved marks: one granted (they change
        // a mark and approve again), one refused, two waiting.
        foreach (array_slice($approved, 0, 4) as $i => $row) {
            $request = app(RequestEdit::class)->handle($row->teacher, $row->subject, $row->section, Period::Month1, 'خطأ في إدخال درجة أحد الطلاب، أرجو السماح بتصحيحها.');
            if ($i === 0) {
                app(ManageAccess::class)->decide($this->owner, $request, true, 2);
                $uuid = $this->studentsOf($row->subject->stage, $row->section)[0];
                $old = GradeMark::query()->where('subject_id', $row->subject->id)->where('student_id', Student::query()->where('uuid', $uuid)->value('id'))->first()?->value();
                $new = $old === null || $old->absent ? Mark::of(55, $row->subject->max_mark) : Mark::of(min($row->subject->max_mark, (int) $old->value + 3), $row->subject->max_mark);
                app(SaveMarks::class)->handle($row->teacher, $row->subject, $row->section, Period::Month1, [$uuid => $new]);
                $this->check('fill', 'a change under a grant clears the approval', null, SectionApproval::find($row->subject, $row->section, Period::Month1)?->id);
                app(ApproveSection::class)->approve($row->teacher, $row->subject, $row->section, Period::Month1);
                // The grant ends once used, as the owner would withdraw it.
                app(ManageAccess::class)->section($this->owner, $row->subject, $row->section, null, null, 0);
            } elseif ($i === 1) {
                app(ManageAccess::class)->decide($this->owner, $request, false, null);
            }
        }
        $this->facts['edit requests'] = GradeEditRequest::query()->count();

        // Behaviour and notes for about one student in eight.
        $notes = ['يحتاج إلى متابعة في الحضور.', 'متميز ومشارك في المحاضرات.', 'تأخر في تسليم الواجبات.', 'ملتزم ومنضبط.'];
        $behaviours = Behaviour::cases();
        foreach (Student::query()->orderBy('id')->get() as $student) {
            if (mt_rand(1, 8) === 1) {
                app(ManageResults::class)->note($this->owner, $student, Period::Month1, $behaviours[mt_rand(0, count($behaviours) - 1)], $notes[mt_rand(0, count($notes) - 1)]);
                $this->facts['students with notes'] = ($this->facts['students with notes'] ?? 0) + 1;
            }
        }

        app(ChangeEntryWindow::class)->handle($this->owner, Period::Month1, WindowStatus::Closed);
    }

    /** A mark around 68 out of 100 (scaled to the subject), one in 25 absent. */
    private function madeUpMark(int $max): Mark
    {
        if (mt_rand(1, 25) === 1) {
            return Mark::absent();
        }
        // Box-Muller.
        $u = max(1e-9, mt_rand() / mt_getrandmax());
        $v = mt_rand() / mt_getrandmax();
        $value = 68 + 16 * sqrt(-2 * log($u)) * cos(2 * M_PI * $v);

        return Mark::of((int) max(0, min($max, round($value * $max / 100))), $max);
    }

    // ------------------------------------------------------------------
    // B1. Results: pass and exemption percentages, grade bands
    // ------------------------------------------------------------------

    private function calculation(): void
    {
        $custom = ['excellent' => 95, 'very_good' => 85, 'good' => 75, 'fair' => 65, 'pass' => 55];
        foreach ([50, 40, 60] as $pass) {
            foreach ([null, 90] as $exemptSubject) {
                foreach ([null, 85] as $exemptAll) {
                    foreach (['defaults' => GradeBands::DEFAULTS, 'custom' => $custom] as $bandsName => $bands) {
                        $this->resultSettings($pass, $exemptSubject, $exemptAll, $bands, 2);
                        foreach ($this->places() as [$stage, $section]) {
                            $this->compareSheet("pass {$pass}, exempt ".json_encode($exemptSubject).'/'.json_encode($exemptAll).", {$bandsName}", $stage, $section);
                        }
                    }
                }
            }
        }

        // Settings that make no sense are refused.
        foreach ([
            'exemption below the pass mark' => fn () => $this->resultSettings(60, 55, null, GradeBands::DEFAULTS, null),
            'grade bands out of order' => fn () => $this->resultSettings(50, null, null, ['excellent' => 80, 'very_good' => 90, 'good' => 70, 'fair' => 60, 'pass' => 50], null),
            'pass at 0%' => fn () => $this->resultSettings(0, null, null, GradeBands::DEFAULTS, null),
            'absence flag of 0' => fn () => $this->resultSettings(50, null, null, GradeBands::DEFAULTS, 0),
        ] as $case => $attempt) {
            $this->check('results', "refuses {$case}", false, $this->succeeds($attempt));
        }
    }

    /**
     * @param  array<string, int>  $bands
     */
    private function resultSettings(int $pass, ?int $exemptSubject, ?int $exemptAll, array $bands, ?int $absenceFlag): void
    {
        $settings = GradeSettings::current();
        app(ManageResults::class)->settings($this->owner, $pass, $exemptSubject, $exemptAll, $bands, $settings->options(), $settings->tones(), $settings->appeal_minutes, $absenceFlag, $settings->appeal_until, $settings->appeal_scope);
    }

    /** One section's sheet against the arithmetic done here from the raw tables. */
    private function compareSheet(string $case, Stage $stage, Section $section): void
    {
        $sheet = app(ResultSheets::class)->section($stage, $section);
        $expected = $this->recount($stage, $section);

        foreach ($sheet['students'] as $row) {
            $own = $expected[$row['uuid']] ?? null;
            if ($own === null) {
                $this->check('results', "{$case}: {$row['name']} is in the section", true, false);

                continue;
            }
            foreach (['total', 'maximum', 'percentage', 'below', 'absences', 'missing', 'complete', 'passed', 'rank', 'band', 'exempt_all', 'frequent_absence'] as $field) {
                $this->check('results', "{$case}: {$stage->value}{$section->value} {$field}", $own[$field], $row[$field]);
            }
            foreach ($row['cells'] as $i => $cell) {
                $this->check('results', "{$case}: subject cell", $own['cells'][$i], $cell === null ? null : [$cell['passed'], $cell['band'], $cell['exempt']]);
            }
        }
        $this->check('results', "{$case}: {$stage->value}{$section->value} student count", count($expected), count($sheet['students']));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function recount(Stage $stage, Section $section): array
    {
        $settings = DB::table('grade_settings')->where('id', GradeSettings::ROW)->sole();
        $passPercent = (int) $settings->pass_percent;
        $exemptPercent = $settings->exempt_subject_percent === null ? null : (int) $settings->exempt_subject_percent;
        $exemptAll = $settings->exempt_overall_percent === null ? null : (int) $settings->exempt_overall_percent;
        $flag = $settings->absence_flag === null ? null : (int) $settings->absence_flag;
        $bands = $settings->grade_bands === null ? GradeBands::DEFAULTS : (array) json_decode((string) $settings->grade_bands, true);

        $subjects = DB::table('grade_subjects')->where('stage', $stage->value)->orderBy('sort_order')->orderBy('name')->get();
        $students = DB::table('grade_students')->where('stage', $stage->value)->where('section', $section->value)->get(['id', 'uuid']);
        $marks = DB::table('grade_marks')->where('period', Period::Month1->value)->whereIn('student_id', $students->pluck('id'))->get()
            ->groupBy('student_id');

        $band = function (float $percent, bool $passed) use ($bands): string {
            if (! $passed) {
                return 'fail';
            }
            foreach (['excellent', 'very_good', 'good', 'fair', 'pass'] as $name) {
                if ($percent >= $bands[$name]) {
                    return $name;
                }
            }

            return 'pass';
        };

        $out = [];
        foreach ($students as $student) {
            $own = $marks->get($student->id, collect())->keyBy('subject_id');
            $total = 0;
            $maximum = 0;
            $below = 0;
            $absences = 0;
            $missing = 0;
            $cells = [];
            foreach ($subjects as $subject) {
                $max = (int) $subject->max_mark;
                $passMark = $subject->pass_mark !== null ? (int) $subject->pass_mark : (int) ceil($max * $passPercent / 100);
                $exemptMark = $subject->exempt_mark !== null ? (int) $subject->exempt_mark : ($exemptPercent === null ? null : (int) ceil($max * $exemptPercent / 100));
                $exemptMark = $exemptMark === null ? null : max($exemptMark, $passMark);
                $maximum += $max;
                $mark = $own->get($subject->id);
                if ($mark === null) {
                    $missing++;
                    $cells[] = null;

                    continue;
                }
                $absent = (bool) $mark->is_absent;
                $points = $absent ? 0 : (int) $mark->mark;
                $passed = ! $absent && $points >= $passMark;
                $total += $points;
                $absences += $absent ? 1 : 0;
                $below += $passed ? 0 : 1;
                $cells[] = [$passed, $band(round($points * 100 / $max, 2), $passed), $exemptMark !== null && ! $absent && $points >= $exemptMark];
            }
            $percentage = $maximum === 0 ? 0.0 : round($total * 100 / $maximum, 2);
            $complete = $missing === 0 && count($subjects) > 0;
            $passedAll = $complete && $below === 0;
            $out[$student->uuid] = [
                'total' => $total, 'maximum' => $maximum, 'percentage' => $percentage, 'below' => $below,
                'absences' => $absences, 'missing' => $missing, 'complete' => $complete, 'passed' => $passedAll,
                'band' => $complete ? $band($percentage, $passedAll) : null,
                'exempt_all' => $passedAll && $exemptAll !== null && $percentage >= $exemptAll,
                'frequent_absence' => $flag !== null && $absences >= $flag,
                'cells' => $cells,
                'rank' => null,
            ];
        }

        // Ranks: complete results only, equal percentages share a rank.
        $complete = array_filter($out, fn (array $row): bool => $row['complete']);
        foreach ($complete as $uuid => $row) {
            $out[$uuid]['rank'] = 1 + count(array_filter($complete, fn (array $other): bool => $other['percentage'] > $row['percentage']));
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // B2. What the student sees
    // ------------------------------------------------------------------

    private function studentView(): void
    {
        $fields = [
            'total' => ['total', 'maximum'], 'percentage' => ['percentage'], 'rank' => ['rank'],
            'failed_count' => ['below'], 'overall_grade' => ['band'], 'exemption' => ['exempt_all'],
            'student_status' => ['status'], 'behaviour' => ['behaviour'], 'notes' => ['notes'], 'absences' => ['absences'],
        ];
        $this->resultSettings(50, 90, 85, GradeBands::DEFAULTS, 2);

        $cases = ['none' => [], 'all' => array_keys(ResultDisplay::OPTIONS)];
        foreach (array_keys(ResultDisplay::OPTIONS) as $option) {
            $cases["only {$option}"] = [$option];
        }

        $sample = $this->sampleStudents(5);
        // The owner's card does not depend on what the student is shown.
        $cards = [];
        foreach ($sample as $student) {
            $cards[$student->id] = app(ResultSheets::class)->student($student);
        }
        foreach ($cases as $case => $shown) {
            $current = GradeSettings::current();
            $options = [];
            foreach (array_keys(ResultDisplay::OPTIONS) as $option) {
                // As the controller does: the appeal box is the appeals tab's.
                $options[$option] = $option === 'appeals' ? $current->options()['appeals'] : in_array($option, $shown, true);
            }
            app(ManageResults::class)->studentView($this->owner, $options, $current->appeal_minutes, $current->appeal_until, $current->appeal_scope, $current->teacher_appeal_replies);
            $saved = GradeSettings::current()->options();
            // The appeal box is the appeals tab's, kept as it was.
            $this->check('student view', "{$case}: saved", $options, $saved);

            foreach ($sample as $student) {
                $full = $cards[$student->id]['student'];
                $card = app(ResultSheets::class)->forStudent($student);
                $row = $card['student'];
                foreach ($fields as $option => $keys) {
                    foreach ($keys as $key) {
                        $this->check('student view', "{$case}: {$key}", $saved[$option] ? $full[$key] : null, $row[$key]);
                    }
                }
                $this->check('student view', "{$case}: no absence flag", false, array_key_exists('frequent_absence', $row));
                $this->check('student view', "{$case}: no section figures", [false, false], [array_key_exists('stats', $card), array_key_exists('absence_flag', $card)]);
                $this->check('student view', "{$case}: marks", $full['marks'], $row['marks']);
                foreach ($row['cells'] as $i => $cell) {
                    $whole = $full['cells'][$i];
                    $this->check('student view', "{$case}: cell", $whole === null ? null : [$saved['mark_grade'] ? $whole['band'] : null, $saved['exemption'] && $whole['exempt']], $cell === null ? null : [$cell['band'], $cell['exempt']]);
                }
                $whole = $cards[$student->id]['subjects'];
                foreach ($card['subjects'] as $i => $subject) {
                    $this->check('student view', "{$case}: teachers and kind", [
                        $saved['teachers'] ? $whole[$i]['teacher'] : null,
                        $saved['teachers'] ? $whole[$i]['assistant'] : null,
                        $saved['kind'] ? $whole[$i]['kind'] : null,
                    ], [$subject['teacher'], $subject['assistant'], $subject['kind']]);
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // B3. What teachers see of their students' results, and when
    // ------------------------------------------------------------------

    private function teacherView(): void
    {
        $this->resultSettings(50, null, null, GradeBands::DEFAULTS, 1);
        $hidden = [
            'total' => ['total', 'maximum'], 'percentage' => ['percentage'], 'rank' => ['rank'],
            'failed_count' => ['below'], 'overall_grade' => ['band'], 'exemption' => ['exempt_all'],
            'student_status' => ['status'], 'behaviour' => ['behaviour'], 'notes' => ['notes'],
        ];
        $cases = ['none' => [], 'all' => array_keys(ResultDisplay::TEACHER_OPTIONS)];
        foreach (array_keys(ResultDisplay::TEACHER_OPTIONS) as $option) {
            $cases["only {$option}"] = [$option];
        }

        $teachers = $this->sampleTeachers(4);
        // Publication: none, the first sample teacher's first section only, all.
        foreach (['none', 'one section', 'all'] as $publication) {
            app(PublishResults::class)->handle($this->owner, Period::Month1, false);
            $first = $teachers[0][1][0] ?? null;
            if ($publication === 'one section' && $first !== null) {
                app(PublishResults::class)->section($this->owner, Period::Month1, $first[0], $first[1], true);
            } elseif ($publication === 'all') {
                app(PublishResults::class)->handle($this->owner, Period::Month1, true);
            }
            $window = GradePeriod::for(Period::Month1);
            // The owner's sheet does not depend on what teachers are shown.
            $sheets = [];
            foreach ($teachers as [, $places]) {
                foreach ($places as [$stage, $section]) {
                    $sheets[$stage->value.$section->value] ??= app(ResultSheets::class)->section($stage, $section);
                }
            }

            foreach (TeacherResults::cases() as $when) {
                foreach ($cases as $case => $shown) {
                    $view = [];
                    foreach (array_keys(ResultDisplay::TEACHER_OPTIONS) as $option) {
                        $view[$option] = in_array($option, $shown, true);
                    }
                    app(ManageResults::class)->teacherView($this->owner, $when, $view);
                    $saved = GradeSettings::current()->teacherView();
                    $this->check('teacher view', "{$case}: saved", $view, $saved);

                    foreach ($teachers as [$teacher, $places]) {
                        foreach ($places as [$stage, $section, $own]) {
                            $label = "{$publication}, {$when->value}, {$case}, {$stage->value}{$section->value}";
                            $published = $window->publishedFor($stage, $section);
                            $allowed = match ($when) {
                                TeacherResults::Off => false,
                                TeacherResults::Published => $published,
                                TeacherResults::Always => true,
                            };
                            $this->check('teacher view', "{$label}: allowed", $allowed, GradeSettings::current()->teacher_results->allows($published));
                            if (! $allowed) {
                                continue;
                            }

                            $sheet = app(ResultSheets::class)->forTeacher($stage, $section, $own, $saved);
                            $full = $sheets[$stage->value.$section->value] ?? throw new RuntimeException('A sample section was not read.');
                            $names = array_column($sheet['subjects'], 'uuid');
                            $expectedSubjects = $saved['other_subjects'] ? array_column($full['subjects'], 'uuid') : array_values(array_filter(array_column($full['subjects'], 'uuid'), fn (string $uuid): bool => in_array($uuid, $own, true)));
                            $this->check('teacher view', "{$label}: subjects", $expectedSubjects, $names);
                            $keep = array_keys(array_filter(array_column($full['subjects'], 'uuid'), fn (string $uuid): bool => in_array($uuid, $expectedSubjects, true)));

                            foreach ($sheet['students'] as $n => $row) {
                                $whole = $full['students'][$n];
                                $marks = array_map(fn (int $i): mixed => $whole['marks'][$i], $keep);
                                $this->check('teacher view', "{$label}: marks", $marks, $row['marks']);
                                foreach ($hidden as $option => $keys) {
                                    foreach ($keys as $key) {
                                        $this->check('teacher view', "{$label}: {$key}", $saved[$option] ? $whole[$key] : null, $row[$key]);
                                    }
                                }
                                // Figures over every subject: only with the other subjects.
                                $absent = count(array_filter($marks, fn (mixed $mark): bool => $mark === Mark::ABSENT));
                                $missing = count(array_filter($marks, fn (mixed $mark): bool => $mark === null));
                                $this->check('teacher view', "{$label}: absences, missing, flag", $saved['other_subjects']
                                    ? [$whole['absences'], $whole['missing'], $whole['frequent_absence']]
                                    : [$absent, $missing, $absent >= 1], [$row['absences'], $row['missing'], $row['frequent_absence']]);
                                foreach ($row['cells'] as $i => $cell) {
                                    $source = $whole['cells'][$keep[$i]];
                                    $this->check('teacher view', "{$label}: cell", $source === null ? null : [$saved['mark_grade'] ? $source['band'] : null, $saved['exemption'] && $source['exempt']], $cell === null ? null : [$cell['band'], $cell['exempt']]);
                                }
                            }
                        }
                    }
                }
            }
        }
        app(PublishResults::class)->handle($this->owner, Period::Month1, false);
    }

    // ------------------------------------------------------------------
    // B4. Who may change marks: department, teacher, section, entry,
    //     publication, approval, grant and editing time
    // ------------------------------------------------------------------

    private function access(): void
    {
        $row = $this->sampleRow();
        $teacher = $row->teacher;
        $subject = $row->subject;
        $section = $row->section;
        $uuid = $this->studentsOf($subject->stage, $section)[0];
        $studentId = (int) Student::query()->where('uuid', $uuid)->value('id');
        $markRow = GradeMark::query()->where('subject_id', $subject->id)->where('student_id', $studentId)->firstOrFail();
        $enteredAt = $markRow->created_at;
        $base = CarbonImmutable::now();
        $modes = TeacherAccess::cases();
        $access = app(ManageAccess::class);

        $deptLimit = GradeSettings::current()->teacher_edit_minutes;
        foreach ($modes as $dept) {
            Carbon::setTestNow($base);
            $this->resetAccess($teacher, $subject, $section);
            $access->settings($this->owner, $this->teacherAccess($dept, $dept === TeacherAccess::Timed ? 60 : null));
            // Read-only and no access send no limit: the saved one is kept
            // for when it is used again (ManageAccess::settings), and it
            // applies to a teacher or section given Edit or Timed.
            if (ManageAccess::usesLimit($dept)) {
                $deptLimit = $dept === TeacherAccess::Timed ? 60 : null;
            }
            $this->check('access', "dept {$dept->value}: saved limit", $deptLimit, GradeSettings::current()->teacher_edit_minutes);

            foreach ([null, ...$modes] as $own) {
                Carbon::setTestNow($base);
                $access->section($this->owner, $subject, $section, null, null, 0);
                $ok = $this->succeeds(fn () => $access->teacher($this->owner, $teacher, $own, null, null));
                $this->check('access', "dept {$dept->value}, teacher ".($own === null ? 'inherit' : $own->value).': saved', ! ($own === TeacherAccess::Timed && $deptLimit === null), $ok);
                if (! $ok) {
                    continue;
                }

                foreach ([null, ...$modes] as $place) {
                    Carbon::setTestNow($base);
                    $ok = $this->succeeds(fn () => $access->section($this->owner, $subject, $section, $place, null, 0));
                    $label = "dept {$dept->value}, teacher ".($own === null ? 'inherit' : $own->value).', section '.($place === null ? 'inherit' : $place->value);
                    $this->check('access', "{$label}: saved", ! ($place === TeacherAccess::Timed && $deptLimit === null), $ok);
                    if (! $ok) {
                        continue;
                    }

                    foreach (['open', 'closed', 'published'] as $state) {
                        foreach ([false, true] as $approved) {
                            foreach (['none', 'active', 'expired'] as $grant) {
                                foreach ([0, 120] as $minutes) {
                                    $now = $base->addMinutes($minutes);
                                    Carbon::setTestNow($now);
                                    $this->accessState($subject, $section, $teacher, $state, $approved, $grant === 'none' ? null : ($grant === 'active' ? $now->addHour() : $now->subHour()));

                                    $mode = $place ?? $own ?? $dept;
                                    $signIn = ($own ?? $dept) !== TeacherAccess::Blocked;
                                    $hidden = $mode === TeacherAccess::Blocked;
                                    $published = $state === 'published';
                                    $grantOn = ! $published && $grant === 'active';
                                    $canWrite = ! $published && ($grantOn || (! $approved && $state === 'open' && in_array($mode, [TeacherAccess::Edit, TeacherAccess::Timed], true)));
                                    $limited = in_array($mode, [TeacherAccess::Edit, TeacherAccess::Timed], true) && $deptLimit !== null;
                                    $canChange = $canWrite && ($grantOn || ! $limited || $enteredAt->addMinutes((int) $deptLimit)->greaterThan($now));

                                    $case = "{$label}, {$state}, ".($approved ? 'approved' : 'not approved').", grant {$grant}, +{$minutes}m";
                                    $freshTeacher = Teacher::query()->whereKey($teacher->id)->firstOrFail();
                                    $freshRow = SubjectTeacher::query()->whereKey($row->row->id)->firstOrFail();
                                    $settings = GradeSettings::current();
                                    $window = GradePeriod::for(Period::Month1);
                                    $policy = SectionAccess::policy($freshTeacher, $freshRow, $settings, $window, $approved, $window->publishedFor($subject->stage, $section));
                                    $this->check('access', "{$case}: sign-in", $signIn, $freshTeacher->maySignIn($settings));
                                    $this->check('access', "{$case}: hidden", $hidden, SectionAccess::hidden($freshTeacher, $freshRow, $settings));
                                    $this->check('access', "{$case}: can write", $canWrite, $policy->canWrite());
                                    $this->check('access', "{$case}: can change an entered mark", $canChange, $policy->canChange($enteredAt));

                                    $current = GradeMark::query()->whereKey($markRow->id)->first()?->value();
                                    $next = $current === null || $current->absent ? Mark::of(1, $subject->max_mark) : Mark::of(((int) $current->value + 1) % ($subject->max_mark + 1), $subject->max_mark);
                                    $saved = $this->succeeds(fn () => app(SaveMarks::class)->handle($freshTeacher, $subject, $section, Period::Month1, [$uuid => $next]));
                                    $this->check('access', "{$case}: teacher's save", $signIn && ! $hidden && $canChange, $saved);
                                }
                            }
                        }
                    }
                }
            }
        }

        // The owner corrects at any time, published or not.
        Carbon::setTestNow($base);
        $this->accessState($subject, $section, $teacher, 'published', true, null);
        $this->check('access', 'the owner corrects a published, approved sheet', true, $this->succeeds(fn () => app(SaveMarks::class)->handle($this->owner, $subject, $section, Period::Month1, [$uuid => Mark::absent()])));
        $this->accessState($subject, $section, $teacher, 'closed', false, null);
        $this->resetAccess($teacher, $subject, $section);
        $access->settings($this->owner, $this->teacherAccess(TeacherAccess::Edit, null));
    }

    private function resetAccess(Teacher $teacher, Subject $subject, Section $section): void
    {
        app(ManageAccess::class)->section($this->owner, $subject, $section, null, null, 0);
        app(ManageAccess::class)->teacher($this->owner, $teacher, null, null, null);
    }

    /** Sets entry, publication, approval and grant directly: the state, not what is tried. */
    private function accessState(Subject $subject, Section $section, Teacher $teacher, string $state, bool $approved, ?CarbonImmutable $grant): void
    {
        GradePeriod::query()->where('period', Period::Month1->value)->update(['results_published_at' => null]);
        SectionPublication::query()->where('period', Period::Month1->value)->delete();
        GradePeriod::query()->where('period', Period::Month1->value)->update($state === 'open' ? ['status' => WindowStatus::Open->value, 'closed_at' => null] : ['status' => WindowStatus::Closed->value, 'closed_at' => now()]);
        if ($state === 'published') {
            (new SectionPublication)->forceFill(['stage' => $subject->stage, 'section' => $section, 'period' => Period::Month1, 'published_at' => now()])->save();
        }

        SectionApproval::query()->where('subject_id', $subject->id)->where('section', $section->value)->delete();
        if ($approved) {
            (new SectionApproval)->forceFill(['subject_id' => $subject->id, 'section' => $section, 'period' => Period::Month1, 'teacher_id' => $teacher->id, 'approved_at' => now()])->save();
        }
        SubjectTeacher::query()->where('subject_id', $subject->id)->where('section', $section->value)->update(['edit_until' => $grant]);
    }

    /**
     * The access settings as they are, with the teachers' mode and editing
     * time (no deadline) changed.
     *
     * @return array{teacher_attempts_per_minute: int, teacher_attempts_per_hour: int, student_attempts_per_minute: int, student_attempts_per_hour: int, teacher_max_logins: int|null, student_max_logins: int|null, teacher_mode: TeacherAccess, teacher_edit_minutes: int|null, teacher_edit_until: CarbonImmutable|null, student_access: StudentAccess, remember_days: int}
     */
    private function teacherAccess(TeacherAccess $mode, ?int $minutes): array
    {
        return [...$this->accessNow(), 'teacher_mode' => $mode, 'teacher_edit_minutes' => $minutes, 'teacher_edit_until' => null];
    }

    /**
     * The access settings as they are, with students' access and cap changed.
     *
     * @return array{teacher_attempts_per_minute: int, teacher_attempts_per_hour: int, student_attempts_per_minute: int, student_attempts_per_hour: int, teacher_max_logins: int|null, student_max_logins: int|null, teacher_mode: TeacherAccess, teacher_edit_minutes: int|null, teacher_edit_until: CarbonImmutable|null, student_access: StudentAccess, remember_days: int}
     */
    private function studentAccess(StudentAccess $access, ?int $cap): array
    {
        return [...$this->accessNow(), 'student_access' => $access, 'student_max_logins' => $cap];
    }

    /**
     * @return array{teacher_attempts_per_minute: int, teacher_attempts_per_hour: int, student_attempts_per_minute: int, student_attempts_per_hour: int, teacher_max_logins: int|null, student_max_logins: int|null, teacher_mode: TeacherAccess, teacher_edit_minutes: int|null, teacher_edit_until: CarbonImmutable|null, student_access: StudentAccess, remember_days: int}
     */
    private function accessNow(): array
    {
        $s = GradeSettings::current();

        return [
            'teacher_attempts_per_minute' => $s->teacher_attempts_per_minute,
            'teacher_attempts_per_hour' => $s->teacher_attempts_per_hour,
            'student_attempts_per_minute' => $s->student_attempts_per_minute,
            'student_attempts_per_hour' => $s->student_attempts_per_hour,
            'teacher_max_logins' => $s->teacher_max_logins,
            'student_max_logins' => $s->student_max_logins,
            'teacher_mode' => $s->teacher_mode,
            'teacher_edit_minutes' => $s->teacher_edit_minutes,
            'teacher_edit_until' => $s->teacher_edit_until,
            'student_access' => $s->student_access,
            'remember_days' => $s->remember_days,
        ];
    }

    // ------------------------------------------------------------------
    // B5. Students' sign-in
    // ------------------------------------------------------------------

    private function studentSignIn(): void
    {
        $student = $this->sampleStudents(1)[0];
        Student::query()->whereKey($student->id)->update(['login_count' => 3]);

        foreach (StudentAccess::cases() as $everyone) {
            foreach ([false, true] as $blocked) {
                foreach (['no cap' => null, 'cap above' => 4, 'cap reached' => 3] as $capName => $cap) {
                    app(ManageAccess::class)->settings($this->owner, $this->studentAccess($everyone, $cap));
                    app(ManageAccess::class)->student($this->owner, $student, $blocked);
                    $expected = $everyone === StudentAccess::Allowed && ! $blocked && $cap !== 3;
                    $this->check('student sign-in', "{$everyone->value}, ".($blocked ? 'stopped' : 'not stopped').", {$capName}", $expected, Student::query()->findOrFail($student->id)->maySignIn(GradeSettings::current()));
                }
            }
        }
        app(ManageAccess::class)->student($this->owner, $student, false);
        app(ManageAccess::class)->settings($this->owner, $this->studentAccess(StudentAccess::Allowed, null));
    }

    // ------------------------------------------------------------------
    // B6. Mark entry and publication: every move from every state
    // ------------------------------------------------------------------

    private function windowAndPublication(): void
    {
        $places = $this->allPlaces();
        $start = GradePeriod::for(Period::Month1);
        $open = $start->isOpen();
        $all = $start->isPublished();
        /** @var array<string, true> $shown */
        $shown = array_fill_keys(array_keys($start->publishedSections()), true);

        $moves = ['open', 'close', 'publish all', 'hide all', 'publish one', 'hide one', 'publish several'];
        for ($step = 0; $step < 300; $step++) {
            $move = $moves[mt_rand(0, count($moves) - 1)];
            [$stage, $section] = $places[mt_rand(0, count($places) - 1)];
            $key = "{$stage->value}|{$section->value}";
            $several = [];
            foreach ($places as $place) {
                if (mt_rand(0, 1) === 1) {
                    $several[] = $place;
                }
            }
            $several = $several === [] ? [$places[0]] : $several;

            [$ok, $attempt] = match ($move) {
                'open' => [! $all && $shown === [], fn () => app(ChangeEntryWindow::class)->handle($this->owner, Period::Month1, WindowStatus::Open)],
                'close' => [true, fn () => app(ChangeEntryWindow::class)->handle($this->owner, Period::Month1, WindowStatus::Closed)],
                'publish all' => [! $open, fn () => app(PublishResults::class)->handle($this->owner, Period::Month1, true)],
                'hide all' => [true, fn () => app(PublishResults::class)->handle($this->owner, Period::Month1, false)],
                'publish one' => [! $open && ! $all, fn () => app(PublishResults::class)->section($this->owner, Period::Month1, $stage, $section, true)],
                'hide one' => [! $all, fn () => app(PublishResults::class)->section($this->owner, Period::Month1, $stage, $section, false)],
                'publish several' => [! $open && ! $all, fn () => app(PublishResults::class)->sections($this->owner, Period::Month1, $several)],
            };
            $this->check('entry and publication', "{$move} (open ".json_encode($open).', all '.json_encode($all).', sections '.count($shown).')', $ok, $this->succeeds($attempt));

            if ($ok) {
                match ($move) {
                    'open' => $open = true,
                    'close' => $open = false,
                    'publish all' => $all = true,
                    'hide all' => [$all, $shown] = [false, []],
                    'publish one' => $shown[$key] = true,
                    'hide one' => $shown = array_diff_key($shown, [$key => true]),
                    'publish several' => $shown += array_fill_keys(array_map(fn (array $place): string => "{$place[0]->value}|{$place[1]->value}", $several), true),
                };
            }

            $window = GradePeriod::for(Period::Month1);
            $this->check('entry and publication', "{$move}: state after", [$open, $all], [$window->isOpen(), $window->isPublished()]);
            foreach ($places as [$s, $p]) {
                $this->check('entry and publication', "{$move}: what students of {$s->value}{$p->value} see", $all || isset($shown["{$s->value}|{$p->value}"]), $window->publishedFor($s, $p));
            }

            // Each move is judged on its own: the state is read back.
            $open = $window->isOpen();
            $all = $window->isPublished();
            $shown = array_fill_keys(array_keys($window->publishedSections()), true);
        }

        app(PublishResults::class)->handle($this->owner, Period::Month1, false);
        app(ChangeEntryWindow::class)->handle($this->owner, Period::Month1, WindowStatus::Closed);
    }

    // ------------------------------------------------------------------
    // B7. Appeals: on/off, who may, from, until, for how long, publication
    // ------------------------------------------------------------------

    private function appeals(): void
    {
        $student = $this->sampleStudents(1, Stage::Second)[0] ?? $this->sampleStudents(1)[0];
        $subject = Subject::query()->where('stage', $student->stage->value)->orderBy('id')->firstOrFail();
        $other = $student->stage->isDivided() ? ($student->section === Section::A ? Section::B : Section::A) : null;
        $foreign = Subject::query()->where('stage', '!=', $student->stage->value)->first();

        $timings = [
            // name => [minutes, from (minutes after publication), until (minutes after), probes => open?]
            'for 60 minutes' => [60, null, null, [30 => true, 90 => false]],
            'until a date' => [null, null, 120, [30 => true, 150 => false]],
            'both, the date first' => [60, null, 30, [20 => true, 45 => false]],
            'both, the time first' => [30, null, 120, [20 => true, 45 => false]],
            'from a later date for 60 minutes' => [60, 1440, null, [30 => false, 1470 => true, 1530 => false]],
            'from a later date until a date' => [null, 1440, 2880, [30 => false, 1500 => true, 2900 => false]],
        ];

        foreach ([true, false] as $enabled) {
            foreach (AppealScope::cases() as $scope) {
                foreach ([true, false] as $allowed) {
                    foreach (['none', 'own section', 'other section', 'all'] as $publication) {
                        if ($publication === 'other section' && $other === null) {
                            continue;
                        }
                        foreach ($timings as $timing => [$minutes, $from, $until, $probes]) {
                            $base = CarbonImmutable::now()->startOfMinute()->addDays(10);
                            Carbon::setTestNow($base);
                            app(PublishResults::class)->handle($this->owner, Period::Month1, false);
                            app(ManageAccess::class)->studentAppeal($this->owner, $student, $allowed);
                            // Minutes after the base moment (null: not set).
                            $at = fn (?int $offset): ?CarbonImmutable => $offset === null ? null : $base->addMinutes($offset);
                            app(ManageResults::class)->appeals($this->owner, $enabled, $minutes, $at($from), $at($until), $scope, false);
                            match ($publication) {
                                'own section' => app(PublishResults::class)->section($this->owner, Period::Month1, $student->stage, $student->section, true),
                                'other section' => app(PublishResults::class)->section($this->owner, Period::Month1, $student->stage, $other, true),
                                'all' => app(PublishResults::class)->handle($this->owner, Period::Month1, true),
                                default => null,
                            };

                            foreach ($probes as $after => $openByTime) {
                                Carbon::setTestNow($base->addMinutes($after));
                                $expected = $enabled && ($scope === AppealScope::All || $allowed)
                                    && in_array($publication, ['own section', 'all'], true) && $openByTime;
                                $label = ($enabled ? 'on' : 'off').", {$scope->value}, ".($allowed ? 'allowed' : 'not allowed').", {$publication}, {$timing}, +{$after}m";
                                $openUntil = ManageAppeals::openUntil(Student::query()->whereKey($student->id)->firstOrFail(), GradeSettings::current(), GradePeriod::for(Period::Month1));
                                $this->check('appeals', "{$label}: open", $expected, $openUntil !== null);

                                $sent = $this->succeeds(fn () => app(ManageAppeals::class)->submit($student, $subject, Period::Month1, 'أرجو إعادة تدقيق الورقة الامتحانية.'));
                                $this->check('appeals', "{$label}: sent", $expected, $sent);
                                if ($sent) {
                                    // A second one on the same subject waits for the first.
                                    $this->check('appeals', "{$label}: one waiting appeal per subject", false, $this->succeeds(fn () => app(ManageAppeals::class)->submit($student, $subject, Period::Month1, 'اعتراض ثانٍ على المادة نفسها.')));
                                    if ($foreign !== null) {
                                        $this->check('appeals', "{$label}: not another stage's subject", false, $this->succeeds(fn () => app(ManageAppeals::class)->submit($student, $foreign, Period::Month1, 'مادة ليست من مرحلتي.')));
                                    }
                                    $this->check('appeals', "{$label}: a reason of 4 letters", false, $this->succeeds(fn () => app(ManageAppeals::class)->submit($student, $subject, Period::Month1, 'لماذ')));
                                    $appeal = GradeAppeal::query()->where('student_id', $student->id)->where('status', AppealStatus::Pending->value)->firstOrFail();
                                    app(ManageAppeals::class)->decide($this->owner, $appeal, false, 'تم التدقيق.');
                                    $this->checkAppealCounts($label);
                                }
                            }
                        }
                    }
                }
            }
        }

        // On with neither a time nor a closing date: refused (flow audit).
        $this->check('appeals', 'on with no time and no date is refused', false, $this->succeeds(fn () => app(ManageResults::class)->appeals($this->owner, true, null, null, null, AppealScope::All, false)));
        Carbon::setTestNow();
        app(PublishResults::class)->handle($this->owner, Period::Month1, false);
    }

    // ------------------------------------------------------------------
    // B8. Who answers an appeal
    // ------------------------------------------------------------------

    private function appealAnswers(): void
    {
        $row = $this->sampleRow();
        $student = Student::query()->where('uuid', $this->studentsOf($row->subject->stage, $row->section)[0])->firstOrFail();
        $stranger = SubjectTeacher::query()->where('teacher_id', '!=', $row->teacher->id)->first()?->teacher;
        app(PublishResults::class)->handle($this->owner, Period::Month1, true);

        foreach ([true, false] as $replies) {
            foreach ([null, TeacherAccess::ViewOnly, TeacherAccess::Blocked] as $place) {
                app(ManageResults::class)->appeals($this->owner, true, 1440, null, null, AppealScope::All, $replies);
                app(ManageAccess::class)->section($this->owner, $row->subject, $row->section, $place, null, 0);
                $appeal = app(ManageAppeals::class)->submit($student, $row->subject, Period::Month1, 'أرجو مراجعة الدرجة.');
                $label = ($replies ? 'teachers answer' : 'the department answers').', section '.($place === null ? 'inherit' : $place->value);

                if ($stranger !== null) {
                    $this->check('appeal answers', "{$label}: another teacher", false, $this->succeeds(fn () => app(ManageAppeals::class)->answerAsTeacher($stranger, $appeal, true, null)));
                }
                $byTeacher = $this->succeeds(fn () => app(ManageAppeals::class)->answerAsTeacher($row->teacher, $appeal, true, 'سيتم تصحيح الدرجة.'));
                $this->check('appeal answers', "{$label}: the subject's teacher", $replies && $place !== TeacherAccess::Blocked, $byTeacher);
                $this->check('appeal answers', "{$label}: the department, after", ! $byTeacher, $this->succeeds(fn () => app(ManageAppeals::class)->decide($this->owner, $appeal, false, null)));
                $this->check('appeal answers', "{$label}: answered once", false, $this->succeeds(fn () => app(ManageAppeals::class)->decide($this->owner, $appeal, true, null)));
                $this->checkAppealCounts($label);
            }
        }
        app(ManageAccess::class)->section($this->owner, $row->subject, $row->section, null, null, 0);
        app(PublishResults::class)->handle($this->owner, Period::Month1, false);
    }

    // ------------------------------------------------------------------
    // C. Final state (kept with --keep)
    // ------------------------------------------------------------------

    private function finalState(): void
    {
        app(ManageResults::class)->appeals($this->owner, true, 7 * 1440, null, null, AppealScope::All, true);
        app(PublishResults::class)->handle($this->owner, Period::Month1, true);

        $reasons = ['أعتقد أن هناك خطأ في جمع درجات الأسئلة.', 'أرجو إعادة تدقيق ورقتي الامتحانية.', 'لم يُصحح السؤال الأخير.', 'كنت حاضراً في الامتحان ولست غائباً.'];
        $students = Student::query()->orderBy('id')->get()->shuffle()->take(18);
        $appeals = [];
        foreach ($students as $student) {
            $subjects = Subject::query()->where('stage', $student->stage->value)->inRandomOrder(mt_rand())->take(mt_rand(1, 2))->get();
            foreach ($subjects as $subject) {
                $appeals[] = app(ManageAppeals::class)->submit($student, $subject, Period::Month1, $reasons[mt_rand(0, count($reasons) - 1)]);
            }
        }
        $this->facts['appeals'] = count($appeals);

        foreach ($appeals as $i => $appeal) {
            $student = Student::query()->findOrFail($appeal->student_id);
            $subject = Subject::query()->findOrFail($appeal->subject_id);
            $teacher = SubjectTeacher::query()->where('subject_id', $subject->id)->where('section', $student->section->value)->first()?->teacher;
            $accept = $i % 3 === 0;
            if ($i % 4 === 0 && $teacher !== null) {
                app(ManageAppeals::class)->answerAsTeacher($teacher, $appeal, $accept, $accept ? 'تمت مراجعة الورقة وستُصحح الدرجة.' : 'الدرجة صحيحة بعد المراجعة.');
            } elseif ($i % 4 === 1) {
                app(ManageAppeals::class)->decide($this->owner, $appeal, $accept, $accept ? 'قُبل الاعتراض وصُححت الدرجة.' : 'رُفض الاعتراض: الدرجة صحيحة.');
            } else {
                continue;
            }
            if ($accept) {
                // Accepting changes no mark: the owner corrects it on the sheet.
                $mark = GradeMark::query()->where('student_id', $student->id)->where('subject_id', $subject->id)->firstOrFail()->value();
                $corrected = $mark->absent ? Mark::of((int) ceil($subject->max_mark / 2), $subject->max_mark) : Mark::of(min($subject->max_mark, (int) $mark->value + 5), $subject->max_mark);
                app(SaveMarks::class)->handle($this->owner, $subject, $student->section, Period::Month1, [$student->uuid => $corrected]);
            }
        }

        // The corrected marks reach the student's card.
        foreach (GradeAppeal::query()->where('status', AppealStatus::Accepted->value)->get() as $appeal) {
            $student = Student::query()->findOrFail($appeal->student_id);
            $card = app(ResultSheets::class)->forStudent($student);
            $index = array_search(Subject::query()->whereKey($appeal->subject_id)->value('uuid'), array_column($card['subjects'], 'uuid'), true);
            $stored = GradeMark::query()->where('student_id', $student->id)->where('subject_id', $appeal->subject_id)->firstOrFail()->value()->toScalar();
            $this->check('final state', 'a corrected mark on the student\'s card', $stored, $card['student']['marks'][$index]);
        }
        $this->checkAppealCounts('final');
    }

    // ------------------------------------------------------------------
    // The control panel's figures and the reports, recounted
    // ------------------------------------------------------------------

    private function checkCards(string $when): void
    {
        $board = app(GradesBoard::class)->show();
        $window = DB::table('grade_periods')->where('period', Period::Month1->value)->sole();
        $this->check('control panel', "{$when}: entry open, all published", [$window->status === 'open', $window->results_published_at !== null], [$board['window']['open'], $board['window']['published']]);
        $this->check('control panel', "{$when}: active teachers", DB::table('grade_teachers')->where('is_active', true)->count(), $board['teachers']);

        foreach ($board['stages'] as $stage) {
            foreach ($stage['students'] as $section => $count) {
                $this->check('control panel', "{$when}: students {$stage['stage']}{$section}", DB::table('grade_students')->where('stage', $stage['stage'])->where('section', $section)->count(), $count);
            }
            foreach ($stage['subjects'] as $subject) {
                $id = (int) DB::table('grade_subjects')->where('uuid', $subject['uuid'])->value('id');
                foreach ($subject['sections'] as $section => $entered) {
                    $raw = DB::table('grade_marks')->join('grade_students', 'grade_students.id', '=', 'grade_marks.student_id')
                        ->where('grade_marks.subject_id', $id)->where('grade_students.section', $section)->where('grade_marks.period', Period::Month1->value)->count();
                    $this->check('control panel', "{$when}: entered {$subject['name']} {$section}", $raw, $entered);
                    $this->check('control panel', "{$when}: approved {$subject['name']} {$section}", DB::table('grade_section_approvals')->where('subject_id', $id)->where('section', $section)->exists(), $subject['approved'][$section]);
                }
            }
        }
        $this->checkAppealCounts($when);
    }

    private function checkAppealCounts(string $when): void
    {
        $counts = (new ReflectionMethod(GradesAdminController::class, 'appealCounts'))->invoke(null);
        $raw = fn (string $status): int => DB::table('grade_appeals')->where('period', Period::Month1->value)->where('status', $status)->count();
        $answered = DB::table('grade_appeals')->where('period', Period::Month1->value)->where('status', '!=', 'pending');
        $this->check('control panel', "{$when}: appeal tiles", [
            'pending' => $raw('pending'),
            'accepted' => $raw('accepted'),
            'rejected' => $raw('rejected'),
            'by_teacher' => (clone $answered)->whereNotNull('decided_by_teacher_id')->count(),
            'by_department' => (clone $answered)->whereNull('decided_by_teacher_id')->count(),
        ], $counts);
    }

    private function checkReports(): void
    {
        $code = Artisan::call('grades:reports-check');
        $this->check('reports', 'grades:reports-check agrees with the raw tables', 0, $code);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Users, roles, teachers (names, codes, activity) and subjects with
     * their teachers: what this command must never change.
     *
     * @return array<string, string>
     */
    private function untouchable(): array
    {
        return [
            'users' => md5((string) json_encode(DB::table('users')->orderBy('id')->get(['id', 'email', 'password', 'two_factor_secret'])->all())),
            'roles' => md5((string) json_encode(DB::table('role_assignments')->orderBy('id')->get()->all())),
            'teachers' => md5((string) json_encode(DB::table('grade_teachers')->orderBy('id')->get(['id', 'name', 'code_digest', 'is_active', 'access_mode', 'edit_minutes', 'edit_until'])->all())),
            'subjects' => md5((string) json_encode(DB::table('grade_subjects')->orderBy('id')->get(['id', 'name', 'stage', 'max_mark', 'pass_mark', 'exempt_mark'])->all())),
            'assignments' => md5((string) json_encode(DB::table('grade_subject_teachers')->orderBy('id')->get(['id', 'subject_id', 'section', 'teacher_id', 'assistant_id', 'kind', 'access_mode'])->all())),
            'students' => md5((string) json_encode(DB::table('grade_students')->orderBy('id')->get(['id', 'full_name', 'stage', 'section', 'code_digest', 'access_blocked', 'appeal_allowed'])->all())),
        ];
    }

    /**
     * Rows of who teaches what, in stages that have students.
     *
     * @return list<FlowAssignment>
     */
    private function rows(): array
    {
        $rows = [];
        foreach (SubjectTeacher::query()->with(['subject', 'teacher'])->orderBy('subject_id')->orderBy('section')->get() as $row) {
            if ($row->subject instanceof Subject && $row->teacher instanceof Teacher && $row->subject->stage->hasSection($row->section)) {
                $rows[] = new FlowAssignment($row, $row->subject, $row->teacher, $row->section);
            }
        }

        return $rows;
    }

    /**
     * @return list<string> UUIDs, by name
     */
    private function studentsOf(Stage $stage, Section $section): array
    {
        return array_values(Student::query()->where('stage', $stage->value)->where('section', $section->value)->orderBy('full_name')->get(['id', 'uuid'])
            ->map(fn (Student $student): string => $student->uuid)->all());
    }

    /**
     * Sections that have students.
     *
     * @return list<array{0: Stage, 1: Section}>
     */
    private function places(): array
    {
        $places = [];
        foreach ($this->allPlaces() as [$stage, $section]) {
            if ($this->studentsOf($stage, $section) !== []) {
                $places[] = [$stage, $section];
            }
        }

        return $places;
    }

    /**
     * @return list<array{0: Stage, 1: Section}>
     */
    private function allPlaces(): array
    {
        $places = [];
        foreach (Stage::cases() as $stage) {
            foreach ($stage->sections() as $section) {
                $places[] = [$stage, $section];
            }
        }

        return $places;
    }

    /**
     * The first students of each section (or of one stage).
     *
     * @return list<Student>
     */
    private function sampleStudents(int $perSection, ?Stage $only = null): array
    {
        $sample = [];
        foreach ($this->places() as [$stage, $section]) {
            if ($only !== null && $stage !== $only) {
                continue;
            }
            foreach (array_slice($this->studentsOf($stage, $section), 0, $perSection) as $uuid) {
                $sample[] = Student::query()->where('uuid', $uuid)->firstOrFail();
            }
        }

        return $sample;
    }

    /**
     * Teachers with their sections and the subjects they teach there.
     *
     * @return list<array{0: Teacher, 1: list<array{0: Stage, 1: Section, 2: list<string>}>}>
     */
    private function sampleTeachers(int $count): array
    {
        $byTeacher = [];
        foreach ($this->rows() as $row) {
            if ($this->studentsOf($row->subject->stage, $row->section) === []) {
                continue;
            }
            $byTeacher[$row->teacher->id][$row->subject->stage->value.$row->section->value][] = $row;
        }
        // Teachers of the most sections first, so divided stages are covered.
        uasort($byTeacher, fn (array $a, array $b): int => count($b) <=> count($a));

        $out = [];
        foreach (array_slice($byTeacher, 0, $count, true) as $places) {
            $list = [];
            $teacher = null;
            foreach ($places as $rows) {
                $teacher = $rows[0]->teacher;
                $list[] = [$rows[0]->subject->stage, $rows[0]->section, array_map(fn (FlowAssignment $row): string => $row->subject->uuid, $rows)];
            }
            if ($teacher !== null) {
                $out[] = [$teacher, $list];
            }
        }

        return $out;
    }

    /** A section of a divided stage with students, else any. */
    private function sampleRow(): FlowAssignment
    {
        $rows = array_values(array_filter($this->rows(), fn (FlowAssignment $row): bool => $this->studentsOf($row->subject->stage, $row->section) !== []));
        foreach ($rows as $row) {
            if ($row->subject->stage->isDivided()) {
                return $row;
            }
        }

        return $rows[0] ?? throw new RuntimeException('No section of a subject has both a teacher and students.');
    }

    private function succeeds(callable $attempt): bool
    {
        try {
            $attempt();

            return true;
        } catch (Throwable $e) {
            if ($e instanceof QueryException) {
                throw $e;
            }

            return false;
        }
    }

    private function check(string $area, string $case, mixed $expected, mixed $actual): void
    {
        $same = $expected === $actual
            || (is_numeric($expected) && is_numeric($actual) && abs((float) $expected - (float) $actual) < 0.005);
        $this->tally[$area] ??= ['ok' => 0, 'failed' => 0];
        $this->tally[$area][$same ? 'ok' : 'failed']++;
        if (! $same) {
            $this->failures[] = [$area, $case, (string) json_encode($expected, JSON_UNESCAPED_UNICODE), (string) json_encode($actual, JSON_UNESCAPED_UNICODE)];
        }
    }

    private function report(bool $kept): void
    {
        $rows = [];
        foreach ($this->tally as $area => $count) {
            $rows[] = [$count['failed'] === 0 ? 'ok' : 'FAILED', $area, $count['ok'] + $count['failed'], $count['failed']];
        }
        $this->table(['', 'area', 'checks', 'failed'], $rows);
        $this->table(['fact', 'count'], array_map(fn (string $key, int $value): array => [$key, $value], array_keys($this->facts), $this->facts));

        if ($this->failures !== []) {
            $this->table(['area', 'case', 'expected', 'actual'], array_slice($this->failures, 0, 40));
        }

        $total = array_sum(array_map(fn (array $count): int => $count['ok'] + $count['failed'], $this->tally));
        $this->line(count($this->failures) === 0 ? "<info>All {$total} checks passed.</info>" : '<error>'.count($this->failures)." of {$total} checks failed.</error>");
        $this->line($kept ? 'Kept: marks, approvals, requests, notes, publication and appeals are saved.' : 'Rolled back: the database is as it was.');

        $path = $this->option('report');
        if (is_string($path) && $path !== '') {
            File::put($path, (string) json_encode([
                'ran_at' => now()->toIso8601String(),
                'kept' => $kept,
                'checks' => $total,
                'areas' => $this->tally,
                'facts' => $this->facts,
                'failures' => $this->failures,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
    }
}
