<?php

// torsade/app/Listeners/RecordAuthenticationActivity.php

namespace App\Listeners;

use App\Enums\ActivityEvent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Actor;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Fortify;

/**
 * Writes every authentication event to the activity log, with the IP address
 * of the request.
 */
final class RecordAuthenticationActivity
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Request $request,
    ) {}

    public function handleLogin(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $event->user->last_login_at = now();
        $event->user->save();

        $this->audit->record(
            ActivityEvent::LoginSucceeded,
            Actor::forUser($event->user, $this->request),
            $event->user,
            properties: ['remember' => $event->remember ?: null],
        );
    }

    public function handleFailed(Failed $event): void
    {
        $this->recordFailure($this->accountFor($event->credentials[Fortify::username()] ?? null), 'credentials');
    }

    public function handleLockout(Lockout $event): void
    {
        $this->recordFailure($this->accountFor($event->request->input(Fortify::username())), 'locked');
    }

    public function handleTwoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        $this->recordFailure($event->user, 'two_factor');
    }

    /**
     * The user finished configuring two-factor authentication: it is active
     * from now on. Generating the secret alone is not logged.
     */
    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->audit->record(
            ActivityEvent::TwoFactorEnabled,
            Actor::forUser($event->user, $this->request),
            $event->user,
        );
    }

    /**
     * The user disabled two-factor authentication. Dropping a setup that was
     * never confirmed changes nothing to the account and is not logged.
     */
    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        if (! $event->user->wasChanged('two_factor_confirmed_at')) {
            return;
        }

        $this->audit->record(
            ActivityEvent::TwoFactorDisabled,
            Actor::forUser($event->user, $this->request),
            $event->user,
        );
    }

    public function handleLogout(Logout $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->audit->record(
            ActivityEvent::LoggedOut,
            Actor::forUser($event->user, $this->request),
            $event->user,
        );
    }

    /**
     * The account matching the e-mail address that was typed, if any.
     *
     * When there is none the typed value is deliberately dropped: it may be
     * a password entered in the wrong field.
     */
    private function accountFor(mixed $email): ?User
    {
        if (! is_string($email) || $email === '') {
            return null;
        }

        return User::query()->where('email', strtolower($email))->first();
    }

    private function recordFailure(?User $account, string $reason): void
    {
        $this->audit->record(
            ActivityEvent::LoginFailed,
            Actor::anonymous($this->request),
            $account,
            properties: ['reason' => $account !== null && ! $account->is_active ? 'inactive' : $reason],
        );
    }
}
