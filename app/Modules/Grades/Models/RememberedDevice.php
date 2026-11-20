<?php

namespace App\Modules\Grades\Models;

use App\Modules\Grades\Domain\DeviceHolder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A browser a teacher or student asked to be remembered on (ADR-0038).
 *
 * @property int $id
 * @property DeviceHolder $holder
 * @property int $holder_id
 * @property string $selector
 * @property string $validator_hash
 * @property string $fingerprint
 * @property string|null $user_agent
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class RememberedDevice extends Model
{
    public const MORPH = 'grade_remembered_device';

    protected $table = 'grade_remembered_devices';

    /** @var list<string> */
    protected $hidden = ['selector', 'validator_hash', 'fingerprint'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'holder' => DeviceHolder::class,
            'holder_id' => 'integer',
            'expires_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
