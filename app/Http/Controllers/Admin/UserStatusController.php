<?php

// torsade/app/Http/Controllers/Admin/UserStatusController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserAccounts;
use App\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Deactivation and reactivation of accounts, behind the "manage-users" gate.
 * Accounts are never deleted, so that the activity log keeps its authors.
 */
class UserStatusController extends Controller
{
    public function deactivate(Request $request, User $user, UserAccounts $accounts): RedirectResponse
    {
        $accounts->deactivate($user, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User deactivated.')]);

        return to_route('users.index');
    }

    public function activate(Request $request, User $user, UserAccounts $accounts): RedirectResponse
    {
        $accounts->reactivate($user, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User reactivated.')]);

        return to_route('users.index');
    }
}
