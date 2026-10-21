<?php

// torsade/app/Http/Controllers/Admin/UserTwoFactorController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserAccounts;
use App\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Two-factor authentication of another account, behind the "manage-users"
 * and "manage-user-two-factor" gates. An administrator can require it or
 * reset it, never enable it: whoever enables it holds the secret.
 */
class UserTwoFactorController extends Controller
{
    public function enforce(Request $request, User $user, UserAccounts $accounts): RedirectResponse
    {
        $accounts->requireTwoFactor($user, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Two-factor authentication is now required for this account.')]);

        return to_route('users.edit', $user);
    }

    public function release(Request $request, User $user, UserAccounts $accounts): RedirectResponse
    {
        $accounts->stopRequiringTwoFactor($user, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Two-factor authentication is no longer required for this account.')]);

        return to_route('users.edit', $user);
    }

    public function reset(Request $request, User $user, UserAccounts $accounts): RedirectResponse
    {
        $accounts->resetTwoFactor($user, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Two-factor authentication reset.')]);

        return to_route('users.edit', $user);
    }
}
