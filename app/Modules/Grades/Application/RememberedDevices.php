<?php

namespace App\Modules\Grades\Application;

use App\Models\User;
use App\Modules\Grades\Application\Actions\EnsureManager;
use App\Modules\Grades\Domain\DeviceHolder;
use App\Modules\Grades\Models\GradeSettings;
use App\Modules\Grades\Models\RememberedDevice;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Remember this device" (ADR-0038), as split tokens: the cookie holds
 * `selector.validator`; the table keeps the selector, a SHA-256 of the
 * validator, and an HMAC fingerprint of the holder's code.
 *
 * - A device lets its holder in again without the code while it has not
 *   expired, the code is the one it was made with, and the holder may sign
 *   in (not stopped, not blocked, under any cap).
 * - Each use replaces the validator, so a copied cookie works once at most;
 *   a known selector with a wrong validator (a copy already used, or a
 *   guess) forgets that device and is audited.
 * - The code never reaches the cookie or the table.
 */
final class RememberedDevices
{
    public const SELECTOR_LENGTH = 24;

    public const VALIDATOR_LENGTH = 40;

    public function __construct(private readonly AuditLogger $audit) {}

    /** Remembers this device for the holder; returns the cookie's token. */
    public function remember(Teacher|Student $holder, int $days, ?string $userAgent): string
    {
        // The holder's expired devices go now (no scheduled pruning).
        RememberedDevice::query()
            ->where('holder', self::kind($holder)->value)
            ->where('holder_id', $holder->id)
            ->where('expires_at', '<=', now())
            ->delete();

        $selector = Str::random(self::SELECTOR_LENGTH);
        $validator = Str::random(self::VALIDATOR_LENGTH);

        $device = new RememberedDevice;
        $device->forceFill([
            'holder' => self::kind($holder),
            'holder_id' => $holder->id,
            'selector' => $selector,
            'validator_hash' => hash('sha256', $validator),
            'fingerprint' => self::fingerprint($holder),
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
            'expires_at' => now()->addDays($days),
            'last_used_at' => now(),
        ])->save();

        return $selector.'.'.$validator;
    }

    /**
     * The holder the token lets in, with the token that replaces it; null
     * when it lets no one in (the cookie should then be forgotten).
     *
     * @return array{0: Teacher|Student, 1: string}|null
     */
    public function consume(DeviceHolder $kind, string $token, GradeSettings $settings): ?array
    {
        if ($settings->remember_days === 0) {
            return null;
        }

        [$selector, $validator] = array_pad(explode('.', $token, 2), 2, '');
        if (strlen($selector) !== self::SELECTOR_LENGTH || strlen($validator) !== self::VALIDATOR_LENGTH) {
            return null;
        }

        return DB::transaction(function () use ($kind, $selector, $validator, $settings): ?array {
            $device = RememberedDevice::query()
                ->where('selector', $selector)
                ->where('holder', $kind->value)
                ->lockForUpdate()
                ->first();

            if ($device === null) {
                return null;
            }

            if (! hash_equals($device->validator_hash, hash('sha256', $validator))) {
                $device->delete();
                $this->audit->record('grades.device_token_mismatch', null, ['holder' => $kind->value, 'holder_id' => $device->holder_id], withoutActor: true);

                return null;
            }

            $holder = $kind === DeviceHolder::Teacher
                ? Teacher::query()->find($device->holder_id)
                : Student::query()->find($device->holder_id);

            // Expired, or made with a code that is no longer the holder's.
            if ($holder === null || $device->expires_at->isPast() || ! hash_equals($device->fingerprint, self::fingerprint($holder))) {
                $device->delete();

                return null;
            }

            // Stopped, blocked or over the cap: kept for when it is lifted.
            if (! $holder->maySignIn($settings)) {
                return null;
            }

            $next = Str::random(self::VALIDATOR_LENGTH);
            $device->forceFill(['validator_hash' => hash('sha256', $next), 'last_used_at' => now()])->save();

            return [$holder, $selector.'.'.$next];
        }, 3);
    }

    /** Forgets the device the token names (signing out). */
    public function forget(DeviceHolder $kind, string $token): void
    {
        $selector = explode('.', $token, 2)[0];

        RememberedDevice::query()->where('holder', $kind->value)->where('selector', $selector)->delete();
    }

    /** The owner forgets every device of a teacher or student; returns how many. */
    public function forgetAll(User $actor, Teacher|Student $holder): int
    {
        return DB::transaction(function () use ($actor, $holder): int {
            EnsureManager::check($actor);

            $count = RememberedDevice::query()
                ->where('holder', self::kind($holder)->value)
                ->where('holder_id', $holder->id)
                ->delete();

            if ($count > 0) {
                $this->audit->record('grades.devices_forgotten', $holder, ['devices' => $count], actor: $actor);
            }

            return $count;
        }, 3);
    }

    public static function kind(Teacher|Student $holder): DeviceHolder
    {
        return $holder instanceof Teacher ? DeviceHolder::Teacher : DeviceHolder::Student;
    }

    /**
     * Changes whenever the holder's code changes: the session guards'
     * "password" (Teacher and Student getAuthPassword), keyed with the
     * application key.
     */
    public static function fingerprint(Teacher|Student $holder): string
    {
        return hash_hmac('sha256', self::kind($holder)->value.'|'.$holder->getAuthPassword(), (string) config('app.key'));
    }
}
