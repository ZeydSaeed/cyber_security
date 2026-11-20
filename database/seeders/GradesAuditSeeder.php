<?php

namespace Database\Seeders;

use App\Access\Role;
use App\Models\User;
use App\Modules\Grades\Application\Actions\ChangeEntryWindow;
use App\Modules\Grades\Application\Actions\IssueStudentCodes;
use App\Modules\Grades\Application\Actions\SaveStudent;
use App\Modules\Grades\Application\Actions\SaveSubject;
use App\Modules\Grades\Application\Actions\SaveTeacher;
use App\Modules\Grades\Application\StudentCodes;
use App\Modules\Grades\Application\TeacherCodes;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\TeachingKind;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Access\RoleAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;

/**
 * A made-up department at full size for the end-to-end audit
 * (tests/audit, playwright.audit.config.ts): 97 teachers, 24 subjects
 * (8 per stage, one in each stage out of 50), 97 students across the five
 * sections, and mark entry open. The audit itself adds the 98th to 100th
 * teacher and student through the pages, and every mark through the
 * teachers' sheets, so the department ends with 100 of each.
 *
 * Runs only on database/audit.sqlite, never on a real database. Everything
 * goes through the module's own actions, as in real use. The codes the
 * audit needs are written to storage/app/private/audit/data.json.
 */
class GradesAuditSeeder extends Seeder
{
    /** Teachers made here; the audit adds the rest through the page. */
    public const SEEDED_TEACHERS = 97;

    /** @var array<int, list<array{0: string, 1: int}>> stage => [name, out of] */
    public const SUBJECTS = [
        1 => [
            ['أساسيات الأمن السيبراني', 100], ['شبكات الحاسوب', 100], ['البرمجة المهيكلة', 100], ['الرياضيات المتقطعة', 100],
            ['أنظمة التشغيل', 100], ['تراكيب البيانات', 100], ['اللغة الإنكليزية', 50], ['حقوق الإنسان والديمقراطية', 100],
        ],
        2 => [
            ['التشفير', 100], ['أمن الشبكات', 100], ['قواعد البيانات', 100], ['البرمجة الكيانية', 100],
            ['هندسة البرمجيات', 100], ['نظم إدارة الهوية', 100], ['الإحصاء والاحتمالية', 50], ['أخلاقيات المهنة', 100],
        ],
        3 => [
            ['اختبار الاختراق', 100], ['التحليل الجنائي الرقمي', 100], ['أمن تطبيقات الويب', 100], ['تحليل البرمجيات الخبيثة', 100],
            ['الحوسبة السحابية الآمنة', 100], ['إدارة المخاطر', 100], ['منهجية البحث العلمي', 50], ['مشروع التخرج', 100],
        ],
    ];

    /** Students per section, seeded here (the audit adds one to 1A, 2B and 3B). */
    public const STUDENTS = ['1A' => 33, '2A' => 17, '2B' => 15, '3A' => 17, '3B' => 15];

    private const FIRST = ['أحمد', 'محمد', 'علي', 'حسين', 'زينب', 'فاطمة', 'مريم', 'حيدر', 'سارة', 'يوسف', 'نور', 'مصطفى', 'رقية', 'عباس', 'هدى', 'كرار', 'آية', 'سجاد', 'بنين', 'جعفر'];

    private const FATHER = ['كريم', 'حسن', 'جاسم', 'عادل', 'قاسم', 'سعد', 'رضا', 'فلاح', 'طارق', 'باسم', 'جواد', 'عماد', 'خالد', 'سلمان', 'ماجد', 'نزار', 'وليد', 'صباح', 'رعد', 'عصام'];

    private const GRAND = ['عبد الله', 'إبراهيم', 'جعفر', 'حميد', 'ناصر', 'كاظم', 'صادق', 'ياسين', 'داود', 'عيسى', 'فاضل', 'هادي', 'رشيد', 'شاكر', 'سالم'];

    public function run(
        Google2FA $google2fa,
        SaveTeacher $teachers,
        SaveSubject $subjects,
        SaveStudent $saveStudent,
        IssueStudentCodes $issue,
        ChangeEntryWindow $window,
        StudentCodes $studentCodes,
        TeacherCodes $teacherCodes,
    ): void {
        $connection = (array) config('database.connections.'.config('database.default'));
        if (app()->environment('production') || ($connection['driver'] ?? '') !== 'sqlite' || ! str_ends_with((string) ($connection['database'] ?? ''), 'audit.sqlite')) {
            throw new RuntimeException('GradesAuditSeeder runs only on database/audit.sqlite.');
        }

        $password = bin2hex(random_bytes(12)).'-Aa1';
        $secret = $google2fa->generateSecretKey(32);
        $owner = User::factory()->create([
            'name' => 'Audit Owner',
            'email' => 'owner@audit.test',
            'password' => $password,
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt((string) json_encode(['audit-recovery-code'])),
            'two_factor_confirmed_at' => now(),
        ]);
        RoleAssignment::query()->forceCreate(['user_id' => $owner->id, 'role' => Role::Administrator->value]);

        $staff = [];
        foreach (range(1, self::SEEDED_TEACHERS) as $n) {
            $title = $n % 3 === 0 ? 'د.' : 'م.';
            $name = sprintf('%s %s %s %s', $title, self::FIRST[$n % 20], self::FATHER[($n * 7) % 20], self::GRAND[($n * 3) % 15]).($n > 20 ? " ({$n})" : '');
            $staff[$n] = $teachers->create($owner, $name, null, $n % 4 === 0 ? 'أمن المعلومات' : 'علوم الحاسوب');
        }

        // Each section of each subject gets its own teacher (1..40), and
        // teachers 41..60 assist one section each; 61..97 teach nothing yet.
        $sheets = [];
        $next = 1;
        foreach (self::SUBJECTS as $stage => $list) {
            foreach ($list as $order => [$name, $max]) {
                $subject = $subjects->create($owner, Stage::from($stage), $name);
                if ($max !== 100) {
                    $subjects->changeScale($owner, $subject, $max, null, null);
                }
                foreach (Stage::from($stage)->sections() as $section) {
                    $teacher = $staff[$next];
                    $subjects->assign($owner, $subject, $teacher, $section);
                    $assistant = $next <= 20 ? $staff[40 + $next] : null;
                    $subjects->describe($owner, $subject, $section, $order % 2 === 0 ? TeachingKind::Theory : TeachingKind::Practical, $assistant);
                    $sheets[] = [
                        'subject' => $subject->uuid,
                        'name' => $name,
                        'stage' => $stage,
                        'section' => $section->value,
                        'max' => $max,
                        'teacher' => $teacher->name,
                        'code' => $teacherCodes->decrypt((string) $teacher->fresh()?->code_encrypted),
                    ];
                    $next++;
                }
            }
        }

        $i = 0;
        foreach (self::STUDENTS as $place => $count) {
            $stage = Stage::from((int) $place[0]);
            foreach (range(1, $count) as $n) {
                $name = sprintf('%s %s %s', self::FIRST[$i % 20], self::FATHER[intdiv($i, 20) % 20], self::GRAND[($i * 7) % 15]);
                // Every student has a number, as the printed card shows it.
                $saveStudent->create($owner, $name, $stage, Section::from($place[1]), null, sprintf('CS-%d-%03d', $stage->value, $i + 1));
                $i++;
            }
        }
        $issue->missing($owner);

        $window->handle($owner, Period::Month1, WindowStatus::Open);

        $students = [];
        foreach (Student::query()->orderBy('id')->get() as $student) {
            $students[] = [
                'name' => $student->full_name,
                'stage' => $student->stage->value,
                'section' => $student->section->value,
                'code' => $studentCodes->decrypt((string) $student->code_encrypted),
            ];
        }

        // A teacher with no subject, to check what such a teacher sees.
        $idle = $staff[90] ?? throw new RuntimeException('GradesAuditSeeder needs a 90th teacher.');

        File::ensureDirectoryExists(storage_path('app/private/audit'));
        File::put(storage_path('app/private/audit/data.json'), (string) json_encode([
            'email' => $owner->email,
            'password' => $password,
            'secret' => $secret,
            'sheets' => $sheets,
            'students' => $students,
            'teachers' => Teacher::query()->count(),
            'idle_teacher' => $idle->name,
            'idle_teacher_code' => $teacherCodes->decrypt((string) $idle->fresh()?->code_encrypted),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        if (count($students) !== array_sum(self::STUDENTS) || count($sheets) !== 40) {
            throw new RuntimeException('GradesAuditSeeder made the wrong number of students or sheets.');
        }
    }
}
