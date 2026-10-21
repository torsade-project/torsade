<?php

// torsade/app/Http/Middleware/EnsurePasswordIsChanged.php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While a user holds a temporary password, the only things they can do are
 * choosing a new one and signing out: a temporary password has been seen by
 * whoever handed it over.
 */
class EnsurePasswordIsChanged
{
    /**
     * The routes that stay reachable with a temporary password.
     */
    private const ALLOWED_ROUTES = [
        'password.change',
        'user-password.update',
        'logout',
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->must_change_password) {
            return $next($request);
        }

        if ($request->routeIs(...self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(Response::HTTP_FORBIDDEN, __('You must change your password before continuing.'));
        }

        return redirect()->route('password.change');
    }
}
