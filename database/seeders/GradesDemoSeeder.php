<?php

namespace Database\Seeders;

use App\Access\Role;
use App\Models\User;
use App\Modules\Grades\Application\Actions\ChangeEntryWindow;
use App\Modules\Grades\Application\Actions\ImportStudents;
use App\Modules\Grades\Application\Actions\IssueStudentCodes;
use App\Modules\Grades\Application\Actions\PublishResults;
use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Application\Actions\SaveSubject;
use App\Modules\Grades\Application\Actions\SaveTeacher;
use App\Modules\Grades\Application\StudentCodes;
use App\Modules\Grades\Domain\Mark;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Access\RoleAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * A made-up department for the owner to try the application with (owner's
 * request of 2026-11-15): five teachers with codes, five subjects in each
 * stage, 12 students in the first stage and 10 in each section of the
 * second, each with a code, and marks (some absences, a few left empty).
 * Entry is then closed and the results published, so all three links work.
 * Everything goes through the module's own actions, under the first
 * administrator's name, so the audit trail looks as in real use.
 *
 * Local only, and only once; it is added next to what is already there.
 * The codes are written to storage/app/private/demo/credentials.txt.
 *
 *   php artisan db:seed --class=GradesDemoSeeder
 */
class GradesDemoSeeder extends Seeder
{
    /** @var list<string> */
    private const TEACHERS = [
        'د. سامر عبد الكريم',
        'م. نور الهدى حسن',
        'د. علي جبار كاظم',
        'م. زينب محمد صالح',
        'د. حيدر فاضل عباس',
    ];

    /** @var array<int, list<string>> stage => five subjects, teacher i teaches subject i */
    private const SUBJECTS = [
        1 => ['أساسيات الأمن السيبراني', 'شبكات الحاسوب', 'البرمجة بلغة بايثون', 'أنظمة التشغيل', 'الرياضيات المتقطعة'],
        2 => ['التشفير', 'أمن الشبكات', 'اختبار الاختراق', 'التحليل الجنائي الرقمي', 'أمن تطبيقات الويب'],
    ];

    /** @var list<string> */
    private const NAMES = [
        'أحمد علي حسين', 'فاطمة كريم جاسم', 'محمد حسن عبد الله', 'زهراء عادل محمود', 'حسين قاسم مهدي',
        'مريم سعد إبراهيم', 'علي رضا جعفر', 'نور فلاح حميد', 'يوسف طارق ناصر', 'سارة باسم عباس',
        'مصطفى جواد كاظم', 'رقية عماد صادق', 'عباس خالد ياسين', 'دعاء سلمان داود', 'كرار حيدر عيسى',
        'آية مهند فاضل', 'سجاد ماجد هادي', 'هدى نزار رشيد', 'مرتضى وليد شاكر', 'بنين أحمد سالم',
        'جعفر صباح عبد الحسين', 'طيبة رعد مجيد', 'حيدر سعدون لطيف', 'ملاك عصام نوري', 'عمار ثامر حسن',
        'زينب قحطان علوان', 'منتظر فراس عبيد', 'غدير مازن كامل', 'أمير ناظم جبار', 'تبارك حامد سعيد',
        'ليث عدنان خضير', 'رسل سمير جليل',
    ];

    public function run(
        SaveTeacher $teachers,
        SaveSubject $subjects,
        ImportStudents $import,
        ChangeEntryWindow $window,
        SaveMarks $marks,
        PublishResults $publish,
        StudentCodes $codes,
        IssueStudentCodes $issue,
    ): void {
        if (app()->environment('production', 'staging')) {
            throw new RuntimeException('GradesDemoSeeder never runs in production or staging.');
        }

        // Next to whatever the owner entered (owner's decision, 2026-11-15),
        // but only once.
        if (Teacher::query()->whereIn('name', self::TEACHERS)->exists()) {
            throw new RuntimeException('GradesDemoSeeder has already run here: its teachers exist.');
        }

        $owner = User::query()
            ->whereIn('id', RoleAssignment::query()->select('user_id')->where('role', Role::Administrator->value))
            ->orderBy('id')
            ->first() ?? throw new RuntimeException('GradesDemoSeeder needs an administrator to act as.');

        $credentials = ["Demo department (made-up names) — local only.\n", 'Links: '.route('grades.admin.overview').'  '.route('grades.teacher.login').'  '.route('grades.student.login')."\n", "Teachers\n"];

        $staff = [];
        foreach (self::TEACHERS as $i => $name) {
            $code = sprintf('demo-teacher-%d', $i + 1);
            $staff[] = $teachers->create($owner, $name, $code);
            $credentials[] = sprintf("  %-28s %s\n", $name, $code);
        }

        $bySubject = [];
        foreach (self::SUBJECTS as $stage => $names) {
            foreach ($names as $i => $name) {
                $bySubject[] = [$subjects->create($owner, Stage::from($stage), $name, $staff[$i]), $staff[$i]];
            }
        }

        // 12 students in the first stage (no sections), 10 in each section of the second.
        $lines = ['name,stage,section'];
        foreach (self::NAMES as $i => $name) {
            $lines[] = match (true) {
                $i < 12 => "{$name},1,",
                $i < 22 => "{$name},2,A",
                default => "{$name},2,B",
            };
        }
        $csv = storage_path('app/private/demo-students-'.bin2hex(random_bytes(6)).'.csv');
        File::ensureDirectoryExists(dirname($csv));
        File::put($csv, implode("\n", $lines));
        try {
            $import->apply($owner, $csv, 'csv', 'demo-students.csv');
        } finally {
            File::delete($csv);
        }

        // Students entered before codes existed get theirs too.
        $issue->missing($owner);

        // Marks need entry open, which needs the results hidden.
        $publish->handle($owner, Period::Month1, false);
        $window->handle($owner, Period::Month1, WindowStatus::Open);

        foreach ($bySubject as $index => [$subject, $teacher]) {
            foreach ($subject->stage->sections() as $section) {
                $values = [];
                $students = Student::query()
                    ->where('stage', $subject->stage->value)->where('section', $section->value)
                    ->orderBy('id')->get(['id', 'uuid']);

                foreach ($students as $n => $student) {
                    // The last subject of each stage leaves two students without a mark.
                    if ($index % 5 === 4 && $n < 2) {
                        continue;
                    }
                    $values[$student->uuid] = ($student->id + $index) % 13 === 0
                        ? Mark::absent()
                        : Mark::of(35 + (($student->id * 37 + $index * 11) % 66));
                }

                $marks->handle($teacher, $subject, $section, Period::Month1, $values);
            }
        }

        $window->handle($owner, Period::Month1, WindowStatus::Closed);
        $publish->handle($owner, Period::Month1, true);

        $credentials[] = "\nStudents (code to type at the student link)\n";
        foreach (Student::query()->orderBy('stage')->orderBy('section')->orderBy('full_name')->get() as $student) {
            $place = $student->stage->isDivided() ? "{$student->stage->value}{$student->section->value}" : (string) $student->stage->value;
            $credentials[] = sprintf("  %-3s %-28s %s\n", $place, $student->full_name, $codes->decrypt((string) $student->code_encrypted));
        }

        $file = storage_path('app/private/demo/credentials.txt');
        File::ensureDirectoryExists(dirname($file));
        File::put($file, implode('', $credentials));

        $this->command?->info('Demo department ready. Codes: '.$file);
    }
}
