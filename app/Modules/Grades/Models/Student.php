<?php

namespace App\Modules\Grades\Models;

use App\Modules\Grades\Database\Factories\StudentFactory;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Domain\StudentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A student in one stage and section (ADR-0034), imported by the owner.
 *
 * @property int $id
 * @property string $uuid
 * @property string $full_name
 * @property string $name_key
 * @property Stage $stage
 * @property Section $section
 * @property string|null $student_number
 * @property string|null $code_digest
 * @property string|null $code_encrypted
 * @property StudentStatus|null $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[UseFactory(StudentFactory::class)]
class Student extends Model implements Authenticatable
{
    use AuthenticatableTrait;

    /** @use HasFactory<StudentFactory> */
    use HasFactory;

    use HasUuids;

    public const MORPH = 'grade_student';

    protected $table = 'grade_students';

    /** The guard students sign in on to see their own result. */
    public const GUARD = 'student';

    /** @var list<string> */
    protected $hidden = ['code_digest', 'code_encrypted'];

    /**
     * The session guard's fingerprint of the "password": the code's digest,
     * so a new code ends the sessions opened with the old one.
     */
    public function getAuthPassword(): string
    {
        return 'grades-student-'.$this->code_digest;
    }

    /** No "remember me" for students: the table has no such column. */
    public function getRememberTokenName(): string
    {
        return '';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => Stage::class,
            'section' => Section::class,
            'status' => StudentStatus::class,
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

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return HasMany<GradeMark, $this>
     */
    public function marks(): HasMany
    {
        return $this->hasMany(GradeMark::class, 'student_id');
    }
}
