<?php

// torsade/app/Actions/Auth/AuthenticateUser.php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Timebox;
use Laravel\Fortify\Fortify;

/**
 * Checks the credentials of a login attempt for Fortify.
 *
 * An unknown e-mail address, a wrong password and a deactivated account all
 * give the same answer, in about the same time, so that nothing reveals
 * whether an account exists.
 */
final class AuthenticateUser
{
    /**
     * Minimum duration of an attempt, in microseconds.
     */
    private const MINIMUM_DURATION = 200_000;

    public function __invoke(Request $request): ?User
    {
        return (new Timebox)->call(function () use ($request): ?User {
            $password = (string) $request->input('password');

            $user = User::query()
                ->where(Fortify::username(), (string) $request->input(Fortify::username()))
                ->first();

            if ($user === null) {
                // Spend the time of a real verification on a throwaway hash.
                Hash::check($password, $this->placeholderHash());

                return null;
            }

            if (! Hash::check($password, $user->password) || ! $user->is_active) {
                return null;
            }

            if (Hash::needsRehash($user->password)) {
                $user->password = $password;
                $user->save();
            }

            return $user;
        }, self::MINIMUM_DURATION);
    }

    /**
     * A hash with the current cost, computed once per process.
     */
    private function placeholderHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('torsade-placeholder');
    }
}
