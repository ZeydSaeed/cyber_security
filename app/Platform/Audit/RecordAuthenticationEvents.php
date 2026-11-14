<?php

namespace App\Platform\Audit;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

/**
 * Authentication events into the audit trail (ADR-0013). Identifiers people
 * type at a failed sign-in are kept only as a keyed hash, so attempts can be
 * correlated without storing what was typed.
 */
class RecordAuthenticationEvents
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function subscribe(Dispatcher $events): void
    {
        // Users' sign-ins only: actor ids are users, so another guard's
        // identity (a Grades teacher, ADR-0034) must never be written as
        // one; such a module records its own sign-ins.
        $events->listen(Login::class, fn (Login $event) => $this->isUsersGuard($event->guard) ? $this->forUser('auth.login', $event->user) : null);
        $events->listen(Logout::class, fn (Logout $event) => $event->user === null || ! $this->isUsersGuard($event->guard) ? null : $this->forUser('auth.logout', $event->user));
        $events->listen(PasswordReset::class, fn (PasswordReset $event) => $this->forUser('auth.password_reset', $event->user));
        $events->listen(PasswordUpdatedViaController::class, fn (PasswordUpdatedViaController $event) => $this->forUser('auth.password_changed', $event->user));
        $events->listen(Verified::class, fn (Verified $event) => $this->forUser('auth.email_verified', $event->user));
        $events->listen(TwoFactorAuthenticationConfirmed::class, fn (TwoFactorAuthenticationConfirmed $event) => $this->forUser('auth.two_factor_confirmed', $event->user));
        $events->listen(TwoFactorAuthenticationDisabled::class, fn (TwoFactorAuthenticationDisabled $event) => $this->forUser('auth.two_factor_disabled', $event->user));
        $events->listen(TwoFactorAuthenticationFailed::class, fn (TwoFactorAuthenticationFailed $event) => $this->forUser('auth.two_factor_failed', $event->user));
        $events->listen(RecoveryCodesGenerated::class, fn (RecoveryCodesGenerated $event) => $this->forUser('auth.recovery_codes_generated', $event->user));
        $events->listen(RecoveryCodeReplaced::class, fn (RecoveryCodeReplaced $event) => $this->forUser('auth.recovery_code_used', $event->user));

        $events->listen(Failed::class, fn (Failed $event) => $this->audit->record('auth.failed', metadata: [
            'identifier_hash' => $this->hashIdentifier((string) ($event->credentials['email'] ?? '')),
        ]));

        $events->listen(Lockout::class, fn (Lockout $event) => $this->audit->record('auth.lockout', metadata: [
            'identifier_hash' => $this->hashIdentifier((string) $event->request->input('email', '')),
        ]));
    }

    private function forUser(string $action, mixed $user): void
    {
        if ($user instanceof Authenticatable && $user instanceof Model) {
            $this->audit->record($action, $user, actor: $user);
        }
    }

    private function isUsersGuard(string $guard): bool
    {
        return $guard === (string) config('auth.defaults.guard', 'web');
    }

    private function hashIdentifier(string $identifier): string
    {
        return substr(hash_hmac('sha256', Str::lower(trim($identifier)), (string) config('platform.audit.hmac_key')), 0, 32);
    }
}
