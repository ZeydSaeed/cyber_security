<?php

namespace App\Models;

use App\Notifications\StoredNotification;
use App\Platform\Access\Contracts\HoldsRoles;
use App\Platform\Access\HasRoles;
use App\Platform\Identity\Contracts\HasSecondFactor;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property string|null $admin_password the password an administrator set, decrypted; null once the person changes it
 * @property Carbon|null $admin_password_set_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'admin_password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements HasSecondFactor, HoldsRoles, MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // Reversible on purpose (owner's request, 2026-11-18), with
            // the application key; only administrators may read it.
            'admin_password' => 'encrypted',
            'admin_password_set_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // A password changed by anything but an administrator (settings,
        // "forgot password") is the person's own: the visible copy goes
        // (owner's decision, 2026-11-18).
        static::saving(function (User $user): void {
            if ($user->exists && $user->isDirty('password') && ! $user->isDirty('admin_password')) {
                $user->admin_password = null;
            }
        });
    }

    /**
     * What an administrator sees for the password: "set" (it can be shown),
     * "changed" (the person changed it) or "unknown" (never set here).
     */
    public function adminPasswordState(): string
    {
        // The stored ciphertext, not the decrypted value: no decryption
        // just to know whether there is one.
        return match (true) {
            ($this->getAttributes()['admin_password'] ?? null) !== null => 'set',
            $this->admin_password_set_at !== null => 'changed',
            default => 'unknown',
        };
    }

    public function hasSecondFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null || $this->passkeys()->exists();
    }

    /**
     * Notifications with times kept to the microsecond (ADR-0029); the
     * database channel writes through this relation.
     *
     * @return MorphMany<StoredNotification, $this>
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(StoredNotification::class, 'notifiable')->latest();
    }
}
