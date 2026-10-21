<?php

// torsade/app/Http/Controllers/Admin/UserPasswordController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserAccounts;
use App\Support\Actor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Password reset by an administrator, behind the "manage-users" gate and
 * the "reset-user-password" gate, which refuses one's own account. There
 * is no reset by e-mail: an internal server often has no mail relay.
 */
class UserPasswordController extends Controller
{
    /**
     * Give the account a new temporary password. Like at creation, it only
     * exists in this response and must be changed at the next login.
     */
    public function store(Request $request, User $user, UserAccounts $accounts): JsonResponse
    {
        $password = $accounts->resetPassword($user, Actor::fromRequest($request));

        return response()
            ->json([
                'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
                'temporary_password' => $password,
            ])
            ->header('Cache-Control', 'no-store');
    }
}
