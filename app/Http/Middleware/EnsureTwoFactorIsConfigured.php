<?php

// torsade/app/Http/Middleware/EnsureTwoFactorIsConfigured.php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When an administrator requires two-factor authentication on an account,
 * the user can do nothing but configure it, and can never disable it.
 */
class EnsureTwoFactorIsConfigured
{
    /**
     * The routes that stay reachable while the setup is pending: the setup
     * page, what it calls, the password confirmation those calls ask for,
     * and the logout.
     */
    private const SETUP_ROUTES = [
        'two-factor.setup',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'logout',
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->two_factor_required) {
            return $next($request);
        }

        // Hiding the button is not enough: the request itself is refused.
        if ($request->routeIs('two-factor.disable')) {
            abort(Response::HTTP_FORBIDDEN, __('Two-factor authentication is required on this account.'));
        }

        // A temporary password is changed first: that middleware runs before
        // this one and has already sent the user where they must go.
        if ($user->must_change_password || ! $user->mustSetUpTwoFactor()) {
            return $next($request);
        }

        if ($request->routeIs(...self::SETUP_ROUTES)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(Response::HTTP_FORBIDDEN, __('You must set up two-factor authentication before continuing.'));
        }

        return redirect()->route('two-factor.setup');
    }
}
