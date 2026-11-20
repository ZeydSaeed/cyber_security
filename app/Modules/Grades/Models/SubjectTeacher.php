<?php

namespace App\Modules\Grades\Models;

use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\TeacherAccess;
use App\Modules\Grades\Domain\TeachingKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who teaches one section of a subject (owner's request, 2026-11-18). A
 * subject of the first stage has section A only; a divided stage's
 * subject may have a different teacher for each section.
 *
 * @property int $id
 * @property int $subject_id
 * @property Section $section
 * @property int $teacher_id
 * @property TeachingKind|null $kind
 * @property int|null $assistant_id
 * @property TeacherAccess|null $access_mode
 * @property string|null $access_reason
 * @property CarbonImmutable|null $edit_until
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class SubjectTeacher extends Model
{
    public const MORPH = 'grade_subject_teacher';

    protected $table = 'grade_subject_teachers';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'section' => Section::class,
            'kind' => TeachingKind::class,
            'access_mode' => TeacherAccess::class,
            'edit_until' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    /**
     * The teacher's assistant in this section, if any (shown only).
     *
     * @return BelongsTo<Teacher, $this>
     */
    public function assistant(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'assistant_id');
    }

    /**
     * Whether the teacher teaches this section of the subject.
     */
    public static function teaches(Teacher $teacher, Subject $subject, Section $section): bool
    {
        return self::query()
            ->where('subject_id', $subject->id)
            ->where('section', $section->value)
            ->where('teacher_id', $teacher->id)
            ->exists();
    }
}
