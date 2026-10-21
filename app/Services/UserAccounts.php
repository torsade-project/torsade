<?php

// torsade/app/Services/UserAccounts.php

namespace App\Services;

use App\Enums\ActivityEvent;
use App\Enums\Role;
use App\Models\User;
use App\Support\Actor;
use App\Support\TemporaryPassword;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Every change to a user account goes through this class, whether it comes
 * from the interface or from an Artisan command: the rules on accounts and
 * roles are enforced here and each change is written to the activity log.
 */
final class UserAccounts
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Create an account with a temporary password that must be changed at the
     * first login. The password is returned once and stored nowhere in clear.
     *
     * @return array{0: User, 1: string}
     */
    public function create(string $name, string $email, Role $role, Actor $actor): array
    {
        $password = TemporaryPassword::generate();

        $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->role = $role;
        $user->is_active = true;
        $user->must_change_password = true;
        $user->save();

        $this->audit->record(ActivityEvent::UserCreated, $actor, $user, new: [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
        ]);

        return [$user, $password];
    }

    /**
     * Update the name, e-mail address and role of an account.
     *
     * @throws ValidationException
     */
    public function update(User $user, string $name, string $email, Role $role, Actor $actor): void
    {
        DB::transaction(function () use ($user, $name, $email, $role, $actor): void {
            $previousRole = $user->role;

            if ($previousRole !== $role) {
                if ($actor->isUser($user)) {
                    throw ValidationException::withMessages([
                        'role' => __('You cannot change your own role.'),
                    ]);
                }

                if ($previousRole === Role::Admin && $user->is_active && $this->isLastActiveAdmin($user)) {
                    throw ValidationException::withMessages([
                        'role' => __('The last active administrator cannot be demoted.'),
                    ]);
                }
            }

            $user->fill(['name' => $name, 'email' => $email]);

            $changed = array_keys($user->getDirty());
            $old = array_intersect_key($user->getOriginal(), array_flip($changed));
            $new = array_intersect_key($user->getAttributes(), array_flip($changed));

            $user->role = $role;
            $user->save();

            if ($changed !== []) {
                $this->audit->record(ActivityEvent::UserUpdated, $actor, $user, $old, $new);
            }

            if ($previousRole !== $role) {
                // The role is part of every session: start over with the new one.
                $this->revokeSessions($user);

                $this->audit->record(
                    ActivityEvent::UserRoleChanged,
                    $actor,
                    $user,
                    ['role' => $previousRole],
                    ['role' => $role],
                );
            }
        });
    }

    /**
     * Let a user change their own name.
     */
    public function rename(User $user, string $name, Actor $actor): void
    {
        $old = $user->name;

        if ($old === $name) {
            return;
        }

        $user->name = $name;
        $user->save();

        $this->audit->record(ActivityEvent::UserUpdated, $actor, $user, ['name' => $old], ['name' => $name]);
    }

    /**
     * Deactivate an account. Accounts are never deleted, so that the activity
     * log keeps its authors.
     *
     * @throws ValidationException
     */
    public function deactivate(User $user, Actor $actor): void
    {
        DB::transaction(function () use ($user, $actor): void {
            if ($actor->isUser($user)) {
                throw ValidationException::withMessages([
                    'user' => __('You cannot deactivate your own account.'),
                ]);
            }

            if (! $user->is_active) {
                return;
            }

            if ($user->role === Role::Admin && $this->isLastActiveAdmin($user)) {
                throw ValidationException::withMessages([
                    'user' => __('The last active administrator cannot be deactivated.'),
                ]);
            }

            $user->is_active = false;
            $user->save();

            $this->revokeSessions($user);

            $this->audit->record(ActivityEvent::UserDeactivated, $actor, $user, ['is_active' => true], ['is_active' => false]);
        });
    }

    public function reactivate(User $user, Actor $actor): void
    {
        if ($user->is_active) {
            return;
        }

        $user->is_active = true;
        $user->save();

        $this->audit->record(ActivityEvent::UserReactivated, $actor, $user, ['is_active' => false], ['is_active' => true]);
    }

    /**
     * Replace the password with a temporary one, returned once. The user must
     * change it at the next login and every open session is closed. Two-factor
     * authentication is left as it is: a new password must not weaken it.
     */
    public function resetPassword(User $user, Actor $actor): string
    {
        $password = TemporaryPassword::generate();

        $user->password = $password;
        $user->must_change_password = true;
        $user->save();

        $this->revokeSessions($user);

        $this->audit->record(ActivityEvent::PasswordReset, $actor, $user);

        return $password;
    }

    /**
     * Let a user set their own password, which lifts the forced change. Their
     * other sessions are closed, in case the old password was known to someone
     * else; the session they are using stays open.
     */
    public function changeOwnPassword(User $user, string $password, Actor $actor, ?string $currentSessionId = null): void
    {
        $user->password = $password;
        $user->must_change_password = false;
        $user->save();

        $this->revokeSessions($user, $currentSessionId);

        $this->audit->record(ActivityEvent::PasswordChanged, $actor, $user);
    }

    /**
     * Require two-factor authentication on an account. Only the user can
     * configure it, since whoever sets it up holds the secret: requiring it
     * sends them to the setup page and forbids them to disable it.
     *
     * @throws AuthorizationException
     */
    public function requireTwoFactor(User $user, Actor $actor): void
    {
        $this->refuseOwnAccount($user, $actor);

        if ($user->two_factor_required) {
            return;
        }

        $user->two_factor_required = true;
        $user->save();

        $this->audit->record(
            ActivityEvent::TwoFactorRequired,
            $actor,
            $user,
            ['two_factor_required' => false],
            ['two_factor_required' => true],
        );
    }

    /**
     * @throws AuthorizationException
     */
    public function stopRequiringTwoFactor(User $user, Actor $actor): void
    {
        $this->refuseOwnAccount($user, $actor);

        if (! $user->two_factor_required) {
            return;
        }

        $user->two_factor_required = false;
        $user->save();

        $this->audit->record(
            ActivityEvent::TwoFactorNoLongerRequired,
            $actor,
            $user,
            ['two_factor_required' => true],
            ['two_factor_required' => false],
        );
    }

    /**
     * Remove the two-factor configuration of an account whose device is lost,
     * and close its sessions. If it is required, the user sets it up again at
     * their next login.
     *
     * @throws AuthorizationException
     */
    public function resetTwoFactor(User $user, Actor $actor): void
    {
        $this->refuseOwnAccount($user, $actor);

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->revokeSessions($user);

        $this->audit->record(ActivityEvent::TwoFactorReset, $actor, $user);
    }

    public function hasActiveAdmin(): bool
    {
        return User::query()->where('role', Role::Admin)->where('is_active', true)->exists();
    }

    /**
     * Whether no other active administrator would remain without this user.
     * The rows are locked so that two concurrent requests cannot both pass.
     */
    private function isLastActiveAdmin(User $user): bool
    {
        return User::query()
            ->where('role', Role::Admin)
            ->where('is_active', true)
            ->whereKeyNot($user->getKey())
            ->lockForUpdate()
            ->count() === 0;
    }

    /**
     * An administrator manages their own two-factor authentication from their
     * settings, like any user, never through the administration actions.
     *
     * @throws AuthorizationException
     */
    private function refuseOwnAccount(User $user, Actor $actor): void
    {
        if ($actor->isUser($user)) {
            throw new AuthorizationException;
        }
    }

    /**
     * Close the sessions of the user: the rows of the database session driver,
     * and the "remember me" cookie through its token. The session given as an
     * exception, if any, is kept.
     */
    private function revokeSessions(User $user, ?string $exceptSessionId = null): void
    {
        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->when($exceptSessionId !== null, fn (Builder $query) => $query->where('id', '!=', $exceptSessionId))
            ->delete();

        $user->setRememberToken(Str::random(60));
        $user->save();
    }
}
