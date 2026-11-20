<?php

namespace App\Modules\Grades\Application\Queries;

use App\Models\User;
use App\Modules\Grades\Domain\GradeBand;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Models\GradeAppeal;
use App\Modules\Grades\Models\GradeMark;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\SectionApproval;
use App\Modules\Grades\Models\SectionPublication;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\SubjectTeacher;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditEvent;
use Carbon\CarbonImmutable;

/**
 * The academic reports (owner's request, 2026-11-20): one period's
 * results, publication, teachers' progress, students' sign-ins and views,
 * appeals, subjects compared, and the top ten of each stage. Every figure
 * comes from ResultSheets (the same results the cards show) or from the
 * audit trail; nothing is stored twice. Read only.
 *
 * Rates are percentages with one decimal, of the students whose result is
 * complete (every mark entered); a section still being marked is counted
 * apart, as "incomplete", never as failed.
 */
final class AcademicReports
{
    /** How many places a stage's top list holds (ties may add more). */
    public const TOP = 10;

    public function __construct(private readonly ResultSheets $sheets) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Period $period = Period::Month1): array
    {
        $sheets = [];
        foreach (Stage::cases() as $stage) {
            foreach ($stage->sections() as $section) {
                $sheets[] = ['stage' => $stage, 'section' => $section, 'sheet' => $this->sheets->section($stage, $section, $period)];
            }
        }

        $sections = array_map(fn (array $place): array => $this->sectionFigures($place['stage'], $place['section'], $place['sheet']), $sheets);
        $subjects = $this->subjects($sheets);

        return [
            'period' => $this->period($period),
            'periods' => array_map(fn (Period $one): array => ['value' => $one->value, 'label' => $one->label()], Period::cases()),
            'totals' => $this->totals($sections),
            'sections' => $sections,
            'stages' => $this->stages($sections),
            'top' => $this->top($sheets),
            'subjects' => $subjects,
            'teachers' => $this->teachers($sheets, $period),
            'unassigned' => $this->unassigned($sheets),
            'students' => $this->students(),
            'appeals' => $this->appeals($period),
            'marks' => $this->markDates($period),
            'activity' => $this->activity(),
        ];
    }

    /**
     * The period's entry window and publication: when entry opened and
     * closed, when everything was published, and each section's own date.
     *
     * @return array<string, mixed>
     */
    private function period(Period $period): array
    {
        $row = GradePeriod::query()->where('period', $period->value)->first();
        $own = SectionPublication::query()->where('period', $period->value)->get()
            ->keyBy(fn (SectionPublication $publication): string => "{$publication->stage->value}|{$publication->section->value}");
        $all = $row?->results_published_at;

        $places = [];
        foreach (Stage::cases() as $stage) {
            foreach ($stage->sections() as $section) {
                $at = $all ?? $own->get("{$stage->value}|{$section->value}")?->published_at;
                $places[] = [
                    'stage' => $stage->value,
                    'section' => $section->value,
                    'divided' => $stage->isDivided(),
                    'published_at' => $at?->toIso8601String(),
                ];
            }
        }

        return [
            'value' => $period->value,
            'label' => $period->label(),
            'open' => $row?->isOpen() ?? false,
            'opened_at' => $row?->opened_at?->toIso8601String(),
            'closed_at' => $row?->closed_at?->toIso8601String(),
            'published_at' => $all?->toIso8601String(),
            'places' => $places,
        ];
    }

    /**
     * One section's figures from its result sheet.
     *
     * @param  array<string, mixed>  $sheet
     * @return array<string, mixed>
     */
    private function sectionFigures(Stage $stage, Section $section, array $sheet): array
    {
        $rows = $sheet['students'];
        $complete = array_values(array_filter($rows, fn (array $row): bool => $row['complete']));
        $passed = count(array_filter($complete, fn (array $row): bool => $row['passed']));
        $bands = array_fill_keys(array_map(fn (GradeBand $band): string => $band->value, GradeBand::cases()), 0);
        foreach ($complete as $row) {
            if ($row['band'] !== null) {
                $bands[$row['band']]++;
            }
        }

        return [
            'stage' => $stage->value,
            'section' => $section->value,
            'divided' => $stage->isDivided(),
            ...self::outcome(
                count($rows),
                count($complete),
                $passed,
                count(array_filter($complete, fn (array $row): bool => (bool) $row['exempt_all'])),
                array_sum(array_map(fn (array $row): float => (float) $row['percentage'], $complete)),
            ),
            'failed_two_or_more' => count(array_filter($complete, fn (array $row): bool => $row['below'] >= 2)),
            'frequent_absence' => count(array_filter($rows, fn (array $row): bool => (bool) ($row['frequent_absence'] ?? false))),
            'bands' => $bands,
        ];
    }

    /**
     * Counts and rates of a group of students.
     *
     * @return array{students: int, complete: int, incomplete: int, passed: int, failed: int, exempt: int, pass_rate: float|null, fail_rate: float|null, exempt_rate: float|null, mean: float|null, percent_sum: float}
     */
    private static function outcome(int $students, int $complete, int $passed, int $exempt, float $percentSum): array
    {
        return [
            'students' => $students,
            'complete' => $complete,
            'incomplete' => $students - $complete,
            'passed' => $passed,
            'failed' => $complete - $passed,
            'exempt' => $exempt,
            'pass_rate' => self::rate($passed, $complete),
            'fail_rate' => self::rate($complete - $passed, $complete),
            'exempt_rate' => self::rate($exempt, $complete),
            'mean' => $complete === 0 ? null : round($percentSum / $complete, 1),
            // Kept unrounded, so a stage's mean is not a mean of rounded means.
            'percent_sum' => round($percentSum, 2),
        ];
    }

    private static function rate(int $part, int $whole): ?float
    {
        return $whole === 0 ? null : round($part * 100 / $whole, 1);
    }

    /**
     * Each stage, from its sections (sums, then the rates again).
     *
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    private function stages(array $sections): array
    {
        $stages = [];
        foreach (Stage::cases() as $stage) {
            $own = array_values(array_filter($sections, fn (array $row): bool => $row['stage'] === $stage->value));
            $stages[] = [
                'stage' => $stage->value,
                ...$this->sum($own),
            ];
        }

        return $stages;
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return array<string, mixed>
     */
    private function totals(array $sections): array
    {
        return $this->sum($sections);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function sum(array $rows): array
    {
        $field = fn (string $name): int => (int) array_sum(array_column($rows, $name));
        $percentSum = (float) array_sum(array_column($rows, 'percent_sum'));
        $bands = array_fill_keys(array_map(fn (GradeBand $band): string => $band->value, GradeBand::cases()), 0);
        foreach ($rows as $row) {
            foreach ($row['bands'] as $band => $count) {
                $bands[$band] += $count;
            }
        }

        return [
            ...self::outcome($field('students'), $field('complete'), $field('passed'), $field('exempt'), $percentSum),
            'failed_two_or_more' => $field('failed_two_or_more'),
            'frequent_absence' => $field('frequent_absence'),
            'bands' => $bands,
        ];
    }

    /**
     * The top of each stage (ADR-0034's ranking, across the stage's
     * sections): complete results by percentage, ties sharing a place.
     *
     * @param  list<array{stage: Stage, section: Section, sheet: array<string, mixed>}>  $sheets
     * @return list<array{stage: int, students: list<array<string, mixed>>}>
     */
    private function top(array $sheets): array
    {
        $result = [];
        foreach (Stage::cases() as $stage) {
            $rows = [];
            foreach ($sheets as $place) {
                if ($place['stage'] !== $stage) {
                    continue;
                }
                foreach ($place['sheet']['students'] as $row) {
                    if ($row['complete']) {
                        $rows[] = [
                            'uuid' => $row['uuid'],
                            'name' => $row['name'],
                            'number' => $row['number'],
                            'section' => $place['section']->value,
                            'percentage' => $row['percentage'],
                            'band' => $row['band'],
                            'passed' => $row['passed'],
                        ];
                    }
                }
            }
            usort($rows, fn (array $a, array $b): int => $b['percentage'] <=> $a['percentage'] ?: strcmp($a['name'], $b['name']));

            $top = [];
            $rank = 0;
            $last = null;
            foreach ($rows as $position => $row) {
                if ($last === null || $row['percentage'] < $last) {
                    $rank = $position + 1;
                    $last = $row['percentage'];
                }
                if ($rank > self::TOP) {
                    break;
                }
                $top[] = [...$row, 'rank' => $rank];
            }

            $result[] = ['stage' => $stage->value, 'divided' => $stage->isDivided(), 'students' => $top];
        }

        return $result;
    }

    /**
     * Each subject, in each section and over its stage: marks entered,
     * absences, passes, exemptions, mean, highest and lowest as
     * percentages of the subject's maximum; then compared with the other
     * subjects of its stage (difference from the stage's mean, place by
     * pass rate).
     *
     * @param  list<array{stage: Stage, section: Section, sheet: array<string, mixed>}>  $sheets
     * @return list<array<string, mixed>>
     */
    private function subjects(array $sheets): array
    {
        $subjects = [];
        foreach ($sheets as $place) {
            $sheet = $place['sheet'];
            foreach ($sheet['subjects'] as $i => $subject) {
                $key = $subject['uuid'];
                $subjects[$key] ??= [
                    'uuid' => $key,
                    'name' => $subject['name'],
                    'stage' => $place['stage']->value,
                    'max' => $subject['max'],
                    'pass' => $subject['pass'],
                    'sections' => [],
                    'percents' => [],
                ];

                $percents = [];
                $entered = 0;
                $absent = 0;
                $passed = 0;
                $exempt = 0;
                foreach ($sheet['students'] as $row) {
                    $mark = $row['marks'][$i] ?? null;
                    $cell = $row['cells'][$i] ?? null;
                    if ($mark === null || $cell === null) {
                        continue;
                    }
                    $entered++;
                    $passed += $cell['passed'] ? 1 : 0;
                    $exempt += $cell['exempt'] ? 1 : 0;
                    if ($mark === 'absent') {
                        $absent++;
                    } else {
                        $percents[] = (float) $cell['percent'];
                    }
                }

                $subjects[$key]['percents'] = [...$subjects[$key]['percents'], ...$percents];
                $subjects[$key]['sections'][] = [
                    'section' => $place['section']->value,
                    'teacher' => $subject['teacher'],
                    'students' => count($sheet['students']),
                    ...self::markFigures($entered, $absent, $passed, $exempt, $percents),
                ];
            }
        }

        // Over the stage, from the sections.
        $rows = [];
        foreach ($subjects as $subject) {
            $sum = fn (string $field): int => (int) array_sum(array_column($subject['sections'], $field));
            $rows[] = [
                'uuid' => $subject['uuid'],
                'name' => $subject['name'],
                'stage' => $subject['stage'],
                'max' => $subject['max'],
                'pass' => $subject['pass'],
                'students' => $sum('students'),
                ...self::markFigures($sum('entered'), $sum('absent'), $sum('passed'), $sum('exempt'), $subject['percents']),
                'sections' => $subject['sections'],
            ];
        }

        // Compared within the stage.
        foreach (Stage::cases() as $stage) {
            $own = array_keys(array_filter($rows, fn (array $row): bool => $row['stage'] === $stage->value && $row['mean'] !== null));
            $means = [];
            foreach ($own as $i) {
                $means[] = (float) $rows[$i]['mean'];
            }
            $stageMean = $means === [] ? null : round(array_sum($means) / count($means), 1);

            $byRate = array_values(array_filter($own, fn (int $i): bool => $rows[$i]['pass_rate'] !== null));
            usort($byRate, fn (int $a, int $b): int => $rows[$b]['pass_rate'] <=> $rows[$a]['pass_rate']);
            foreach ($rows as $i => $row) {
                if ($row['stage'] !== $stage->value) {
                    continue;
                }
                $place = array_search($i, $byRate, true);
                $rows[$i]['stage_mean'] = $stageMean;
                $rows[$i]['difference'] = $row['mean'] === null || $stageMean === null ? null : round($row['mean'] - $stageMean, 1);
                $rows[$i]['place'] = $place === false ? null : $place + 1;
                $rows[$i]['of'] = count($byRate);
            }
        }

        return $rows;
    }

    /**
     * @param  list<float>  $percents  present students' marks, as percentages
     * @return array{entered: int, absent: int, passed: int, failed: int, exempt: int, pass_rate: float|null, absence_rate: float|null, mean: float|null, highest: float|null, lowest: float|null}
     */
    private static function markFigures(int $entered, int $absent, int $passed, int $exempt, array $percents): array
    {
        return [
            'entered' => $entered,
            'absent' => $absent,
            'passed' => $passed,
            'failed' => $entered - $passed,
            'exempt' => $exempt,
            'pass_rate' => self::rate($passed, $entered),
            'absence_rate' => self::rate($absent, $entered),
            'mean' => $percents === [] ? null : round(array_sum($percents) / count($percents), 1),
            'highest' => $percents === [] ? null : max($percents),
            'lowest' => $percents === [] ? null : min($percents),
        ];
    }

    /**
     * Each teacher's progress: the subject sections they teach, how many
     * marks are entered of how many students, and which they approved
     * (ADR-0041); with their sign-ins.
     *
     * @param  list<array{stage: Stage, section: Section, sheet: array<string, mixed>}>  $sheets
     * @return list<array<string, mixed>>
     */
    private function teachers(array $sheets, Period $period): array
    {
        // Marks entered per subject and section, from the sheets.
        $entered = [];
        $students = [];
        foreach ($sheets as $place) {
            foreach ($place['sheet']['subjects'] as $i => $subject) {
                $key = "{$subject['uuid']}|{$place['section']->value}";
                $students[$key] = count($place['sheet']['students']);
                $entered[$key] = count(array_filter($place['sheet']['students'], fn (array $row): bool => ($row['marks'][$i] ?? null) !== null));
            }
        }

        $approvals = SectionApproval::query()->where('period', $period->value)->get(['subject_id', 'section', 'approved_at'])
            ->keyBy(fn (SectionApproval $approval): string => "{$approval->subject_id}|{$approval->section->value}");
        $assignments = SubjectTeacher::query()->with('subject:id,uuid,name,stage')->get(['id', 'subject_id', 'section', 'teacher_id'])->groupBy('teacher_id');

        $rows = [];
        foreach (Teacher::query()->orderBy('name')->get(['id', 'uuid', 'name', 'specialty', 'is_active', 'login_count', 'last_login_at']) as $teacher) {
            $own = [];
            foreach ($assignments->get($teacher->id, collect()) as $assignment) {
                $subject = $assignment->subject;
                $key = "{$subject->uuid}|{$assignment->section->value}";
                $approval = $approvals->get("{$subject->id}|{$assignment->section->value}");
                $own[] = [
                    'subject' => $subject->name,
                    'stage' => $subject->stage->value,
                    'section' => $assignment->section->value,
                    'divided' => $subject->stage->isDivided(),
                    'students' => $students[$key] ?? 0,
                    'entered' => $entered[$key] ?? 0,
                    'approved_at' => $approval?->approved_at?->toIso8601String(),
                ];
            }
            usort($own, fn (array $a, array $b): int => [$a['stage'], $a['section'], $a['subject']] <=> [$b['stage'], $b['section'], $b['subject']]);

            $complete = count(array_filter($own, fn (array $one): bool => $one['students'] > 0 && $one['entered'] >= $one['students']));
            $approved = count(array_filter($own, fn (array $one): bool => $one['approved_at'] !== null));
            $any = array_sum(array_column($own, 'entered')) > 0;

            $rows[] = [
                'uuid' => $teacher->uuid,
                'name' => $teacher->name,
                'specialty' => $teacher->specialty,
                'active' => $teacher->is_active,
                'logins' => $teacher->login_count,
                'last_login_at' => $teacher->last_login_at?->toIso8601String(),
                'assignments' => $own,
                'complete' => $complete,
                'approved' => $approved,
                // approved: every section approved; complete: every mark in;
                // started: some marks; none: nothing yet; free: teaches nothing.
                'state' => self::state(count($own), $complete, $approved, $any),
            ];
        }

        return $rows;
    }

    /**
     * A teacher's progress in one word: approved (every section approved),
     * complete (every mark in), started (some marks), none (nothing yet),
     * free (teaches nothing).
     */
    private static function state(int $sections, int $complete, int $approved, bool $any): string
    {
        if ($sections === 0) {
            return 'free';
        }
        if ($approved >= $sections) {
            return 'approved';
        }
        if ($complete >= $sections) {
            return 'complete';
        }

        return $any ? 'started' : 'none';
    }

    /**
     * Subject sections with no teacher yet.
     *
     * @param  list<array{stage: Stage, section: Section, sheet: array<string, mixed>}>  $sheets
     * @return list<array{subject: string, stage: int, section: string, divided: bool}>
     */
    private function unassigned(array $sheets): array
    {
        $rows = [];
        foreach ($sheets as $place) {
            foreach ($place['sheet']['subjects'] as $subject) {
                if ($subject['teacher'] === null) {
                    $rows[] = [
                        'subject' => $subject['name'],
                        'stage' => $place['stage']->value,
                        'section' => $place['section']->value,
                        'divided' => $place['stage']->isDivided(),
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * Each student's sign-ins and views of their result (ADR-0045): how
     * many times, and the last. Three queries.
     *
     * @return list<array<string, mixed>>
     */
    private function students(): array
    {
        $views = AuditEvent::query()
            ->where('action', 'grades.student_result_viewed')
            ->where('subject_type', Student::MORPH)
            ->get(['subject_id', 'occurred_at'])
            ->groupBy('subject_id');

        $rows = [];
        foreach (Student::query()->orderBy('stage')->orderBy('section')->orderBy('full_name')->get(['id', 'uuid', 'full_name', 'student_number', 'stage', 'section', 'login_count', 'last_login_at', 'access_blocked']) as $student) {
            $own = $views->get((string) $student->id, collect());
            $last = $own->max('occurred_at');
            $rows[] = [
                'uuid' => $student->uuid,
                'name' => $student->full_name,
                'number' => $student->student_number,
                'stage' => $student->stage->value,
                'section' => $student->section->value,
                'divided' => $student->stage->isDivided(),
                'blocked' => $student->access_blocked,
                'logins' => $student->login_count,
                'last_login_at' => $student->last_login_at?->toIso8601String(),
                'views' => $own->count(),
                'last_view_at' => $last === null ? null : CarbonImmutable::parse($last)->toIso8601String(),
            ];
        }

        return $rows;
    }

    /**
     * Every appeal of the period (ADR-0042): who appealed which subject,
     * the subject's teacher in the student's section, the answer and who
     * gave it.
     *
     * @return list<array<string, mixed>>
     */
    private function appeals(Period $period): array
    {
        $appeals = GradeAppeal::query()
            ->with(['student:id,full_name,stage,section', 'subject:id,name,stage', 'decidedByTeacher:id,name'])
            ->where('period', $period->value)
            ->orderByDesc('created_at')
            ->get();
        $teachers = SubjectTeacher::query()->with('teacher:id,name')->whereIn('subject_id', $appeals->pluck('subject_id')->unique())->get()
            ->keyBy(fn (SubjectTeacher $row): string => "{$row->subject_id}|{$row->section->value}");
        $users = User::query()->whereIn('id', $appeals->pluck('decided_by_user_id')->filter()->unique())->pluck('name', 'id');

        $rows = [];
        foreach ($appeals as $appeal) {
            $student = $appeal->student;
            $subject = $appeal->subject;
            // Both are deleted with the appeal (cascade); a row without
            // them cannot exist, and is skipped if it ever did.
            if ($student === null || $subject === null) {
                continue;
            }
            $teacher = $appeal->decidedByTeacher;
            $by = match (true) {
                $teacher instanceof Teacher => $teacher->name,
                $appeal->decided_by_user_id !== null => (string) ($users[$appeal->decided_by_user_id] ?? __('A user')),
                default => null,
            };
            $assigned = $teachers->get("{$appeal->subject_id}|{$student->section->value}");

            $rows[] = [
                'uuid' => $appeal->uuid,
                'student' => $student->full_name,
                'stage' => $student->stage->value,
                'section' => $student->section->value,
                'divided' => $student->stage->isDivided(),
                'subject' => $subject->name,
                'teacher' => $assigned?->teacher?->name,
                'reason' => $appeal->reason,
                'status' => $appeal->status->value,
                'response' => $appeal->response,
                'decided_by' => $by,
                'decided_by_teacher' => $appeal->decided_by_teacher_id !== null,
                'created_at' => $appeal->created_at->toIso8601String(),
                'decided_at' => $appeal->decided_at?->toIso8601String(),
            ];
        }

        return $rows;
    }

    /**
     * When each subject section's marks were entered (ADR-0043): the first
     * entry, the last change, and how many mark changes the audit trail
     * holds for it (each entry and each correction).
     *
     * @return list<array<string, mixed>>
     */
    private function markDates(Period $period): array
    {
        $dates = GradeMark::query()
            ->join('grade_students', 'grade_students.id', '=', 'grade_marks.student_id')
            ->where('grade_marks.period', $period->value)
            ->groupBy('grade_marks.subject_id', 'grade_students.section')
            ->selectRaw('grade_marks.subject_id as subject_id, grade_students.section as section, count(*) as marks, min(grade_marks.created_at) as first_at, max(grade_marks.updated_at) as last_at')
            ->toBase()
            ->get();

        $changes = [];
        AuditEvent::query()
            ->where('action', 'grades.marks_saved')
            ->where('subject_type', Subject::MORPH)
            ->get(['subject_id', 'changes', 'metadata', 'actor_id'])
            ->each(function (AuditEvent $event) use (&$changes, $period): void {
                if (($event->metadata['period'] ?? null) !== $period->value) {
                    return;
                }
                $key = "{$event->subject_id}|".($event->metadata['section'] ?? '');
                $changes[$key] ??= ['all' => 0, 'by_owner' => 0];
                $count = count((array) $event->changes);
                $changes[$key]['all'] += $count;
                $changes[$key]['by_owner'] += $event->actor_id !== null ? $count : 0;
            });

        $subjects = Subject::query()->whereIn('id', $dates->pluck('subject_id')->unique())->get(['id', 'name', 'stage'])->keyBy('id');

        $rows = [];
        foreach ($dates as $row) {
            $subject = $subjects->get((int) $row->subject_id);
            if ($subject === null) {
                continue;
            }
            $key = "{$subject->id}|{$row->section}";
            $rows[] = [
                'subject' => $subject->name,
                'stage' => $subject->stage->value,
                'section' => (string) $row->section,
                'divided' => $subject->stage->isDivided(),
                'marks' => (int) $row->marks,
                'first_at' => CarbonImmutable::parse((string) $row->first_at)->toIso8601String(),
                'last_at' => CarbonImmutable::parse((string) $row->last_at)->toIso8601String(),
                'changes' => $changes[$key]['all'] ?? 0,
                // Corrections after the first entry of each mark.
                'corrections' => max(0, ($changes[$key]['all'] ?? 0) - (int) $row->marks),
                'by_owner' => $changes[$key]['by_owner'] ?? 0,
            ];
        }
        usort($rows, fn (array $a, array $b): int => [$a['stage'], $a['section'], $a['subject']] <=> [$b['stage'], $b['section'], $b['subject']]);

        return $rows;
    }

    /**
     * Sign-ins and views per day, in the department's time zone, for the
     * last days (config grades.report_days).
     *
     * @return array{days: list<array{day: string, student_logins: int, teacher_logins: int, views: int}>, student_logins: int, teacher_logins: int, views: int}
     */
    private function activity(): array
    {
        $zone = (string) config('grades.report_timezone', 'UTC');
        $length = (int) config('grades.report_days', 60);
        $today = CarbonImmutable::now($zone)->startOfDay();
        $from = $today->subDays($length - 1);

        $days = [];
        for ($day = $from; $day <= $today; $day = $day->addDay()) {
            $days[$day->format('Y-m-d')] = ['day' => $day->format('Y-m-d'), 'student_logins' => 0, 'teacher_logins' => 0, 'views' => 0];
        }

        $field = ['grades.student_login' => 'student_logins', 'grades.teacher_login' => 'teacher_logins', 'grades.student_result_viewed' => 'views'];
        AuditEvent::query()
            ->whereIn('action', array_keys($field))
            ->where('occurred_at', '>=', $from->utc()->format('Y-m-d H:i:s'))
            ->get(['action', 'occurred_at'])
            ->each(function (AuditEvent $event) use (&$days, $field, $zone): void {
                $day = CarbonImmutable::parse($event->occurred_at)->setTimezone($zone)->format('Y-m-d');
                if (isset($days[$day])) {
                    $days[$day][$field[$event->action]]++;
                }
            });

        $days = array_values($days);

        return [
            'days' => $days,
            'student_logins' => array_sum(array_column($days, 'student_logins')),
            'teacher_logins' => array_sum(array_column($days, 'teacher_logins')),
            'views' => array_sum(array_column($days, 'views')),
        ];
    }
}
