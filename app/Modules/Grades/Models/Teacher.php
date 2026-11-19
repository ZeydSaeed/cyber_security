<?php

namespace App\Modules\Grades\Models;

use App\Modules\Grades\Database\Factories\TeacherFactory;
use App\Modules\Grades\Domain\TeacherAccess;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A teacher who enters marks with a code the owner sets (ADR-0034). Not a
 * user: no password, no second factor, and reaches only mark entry, on the
 * `teacher` guard. The code (the employee number) is kept as an HMAC digest
 * to find the teacher and encrypted so the owner can see it again
 * (TeacherCodes); there is no "remember me".
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $specialty
 * @property string $code_digest
 * @property string|null $code_encrypted
 * @property bool $is_active
 * @property CarbonImmutable|null $last_seen_at
 * @property int $lock_version
 * @property TeacherAccess|null $access_mode
 * @property int|null $edit_hours
 * @property string|null $access_reason
 * @property int $login_count
 * @property CarbonImmutable|null $last_login_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[UseFactory(TeacherFactory::class)]
class Teacher extends Model implements Authenticatable
{
    use AuthenticatableTrait;

    /** @use HasFactory<TeacherFactory> */
    use HasFactory;

    use HasUuids;

    /** The guard teachers sign in on (GradesServiceProvider). */
    public const GUARD = 'teacher';

    public const MORPH = 'grade_teacher';

    protected $table = 'grade_teachers';

    /** @var list<string> */
    protected $hidden = ['code_digest', 'code_encrypted'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'lock_version' => 'integer',
            'last_seen_at' => 'immutable_datetime',
            'access_mode' => TeacherAccess::class,
            'edit_hours' => 'integer',
            'login_count' => 'integer',
            'last_login_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * The session guard keeps a fingerprint of the "password" to notice a
     * change. A teacher has none; the lock version is what changes when the
     * code changes or the teacher is stopped (TeacherSession checks it too).
     */
    public function getAuthPassword(): string
    {
        return 'grades-teacher-v'.$this->lock_version;
    }

    /**
     * No "remember me" for teachers: the table has no such column.
     */
    public function getRememberTokenName(): string
    {
        return '';
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * What the teacher may do: their own mode, or the department's (ADR-0037).
     */
    public function accessMode(GradeSettings $settings): TeacherAccess
    {
        return $this->access_mode ?? $settings->teacher_mode;
    }

    /**
     * Whether the teacher may sign in now: active, not blocked, and under
     * the department's cap on sign-ins, if there is one.
     */
    public function maySignIn(GradeSettings $settings): bool
    {
        return $this->is_active
            && $this->accessMode($settings) !== TeacherAccess::Blocked
            && ($settings->teacher_max_logins === null || $this->login_count < $settings->teacher_max_logins);
    }

    /**
     * The sections of subjects the teacher teaches, one row each.
     *
     * @return HasMany<SubjectTeacher, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(SubjectTeacher::class, 'teacher_id');
    }
}
