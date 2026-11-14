<?php

namespace App\Modules\Grades\Models;

use App\Modules\Grades\Database\Factories\TeacherFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A teacher who enters marks with a code the owner sets (ADR-0034). Not a
 * user: no password, no second factor, and reaches only mark entry. The code
 * is kept as an HMAC digest (TeacherCodes), never as written.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $code_digest
 * @property bool $is_active
 * @property CarbonImmutable|null $last_seen_at
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[UseFactory(TeacherFactory::class)]
class Teacher extends Model
{
    /** @use HasFactory<TeacherFactory> */
    use HasFactory;

    use HasUuids;

    public const MORPH = 'grade_teacher';

    protected $table = 'grade_teachers';

    /** @var list<string> */
    protected $hidden = ['code_digest'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'lock_version' => 'integer',
            'last_seen_at' => 'immutable_datetime',
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
     * @return HasMany<Subject, $this>
     */
    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class, 'teacher_id');
    }
}
