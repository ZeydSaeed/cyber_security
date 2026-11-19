<?php

namespace App\Modules\Grades\Application\Actions;

use App\Models\User;
use App\Modules\Grades\Application\SectionAccess;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Models\GradeMark;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\GradeSettings;
use App\Modules\Grades\Models\SectionApproval;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\SubjectTeacher;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditLogger;
use App\Platform\Exceptions\DomainRuleViolation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * A teacher approves a section's marks (ADR-0041): every student has a
 * mark, and the teacher could still change them. Approved marks are
 * read-only for the teacher; the owner may withdraw the approval.
 */
class ApproveSection
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function approve(Teacher $teacher, Subject $subject, Section $section, Period $period): SectionApproval
    {
        return DB::transaction(function () use ($teacher, $subject, $section, $period): SectionApproval {
            $locked = Subject::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            $settings = GradeSettings::current();
            $fresh = Teacher::query()->whereKey($teacher->id)->first();
            $row = SubjectTeacher::query()
                ->where('subject_id', $locked->id)
                ->where('section', $section->value)
                ->where('teacher_id', $teacher->id)
                ->first();

            if ($fresh === null || $row === null || ! $fresh->is_active || SectionAccess::hidden($fresh, $row, $settings)) {
                throw new AuthorizationException;
            }

            if (SectionApproval::find($locked, $section, $period) !== null) {
                throw new DomainRuleViolation(__('These marks are already approved.'), 'approval');
            }

            $window = GradePeriod::for($period);
            $policy = SectionAccess::policy($fresh, $row, $settings, $window, false, $window->publishedFor($locked->stage, $section));
            if (! $policy->canWrite()) {
                throw new DomainRuleViolation(__('These marks cannot be changed now, so they cannot be approved.'), 'approval');
            }

            $students = Student::query()->where('stage', $locked->stage->value)->where('section', $section->value)->pluck('id');
            $entered = GradeMark::query()
                ->where('subject_id', $locked->id)
                ->where('period', $period->value)
                ->whereIn('student_id', $students)
                ->count();
            if ($students->isEmpty() || $entered < $students->count()) {
                throw new DomainRuleViolation(__('Every student needs a mark before approving.'), 'approval');
            }

            $approval = new SectionApproval;
            $approval->forceFill([
                'subject_id' => $locked->id,
                'section' => $section,
                'period' => $period,
                'teacher_id' => $fresh->id,
                'approved_at' => now(),
            ])->save();

            $this->audit->record('grades.section_approved', $locked, ['section' => $section->value, 'teacher' => $fresh->uuid, 'teacher_name' => $fresh->name], withoutActor: true);

            return $approval;
        }, 3);
    }

    /**
     * A student who joins a section (added, imported or moved there) has
     * no mark in it yet, so the section's approvals of the subjects they
     * lack a mark in no longer say "every student has a mark": they are
     * withdrawn, and the teacher approves again once the mark is in
     * (audit of 2026-11-20). Runs inside the caller's transaction.
     */
    public function releaseForNewcomer(User $actor, Student $student): void
    {
        $approvals = SectionApproval::query()
            ->where('section', $student->section->value)
            ->whereIn('subject_id', Subject::query()->select('id')->where('stage', $student->stage->value))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('grade_marks')
                ->whereColumn('grade_marks.subject_id', 'grade_section_approvals.subject_id')
                ->whereColumn('grade_marks.period', 'grade_section_approvals.period')
                ->where('grade_marks.student_id', $student->id))
            ->get();

        foreach ($approvals as $approval) {
            $subject = Subject::query()->whereKey($approval->subject_id)->first();
            $approval->delete();
            if ($subject !== null) {
                $this->audit->record('grades.section_approval_cleared', $subject, [
                    'section' => $student->section->value,
                    'reason' => 'student_joined',
                    'student_name' => $student->full_name,
                ], actor: $actor);
            }
        }
    }

    /** The owner reopens approved marks to their teacher. */
    public function withdraw(User $actor, Subject $subject, Section $section, Period $period): void
    {
        DB::transaction(function () use ($actor, $subject, $section, $period): void {
            EnsureManager::check($actor);

            $deleted = SectionApproval::query()
                ->where('subject_id', $subject->id)
                ->where('section', $section->value)
                ->where('period', $period->value)
                ->delete();

            if ($deleted > 0) {
                $this->audit->record('grades.section_approval_withdrawn', $subject, ['section' => $section->value], actor: $actor);
            }
        }, 3);
    }
}
