<?php

// torsade/app/Http/Controllers/Auth/PasswordChangeController.php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordChangeController extends Controller
{
    /**
     * Show the page where a user holding a temporary password must choose
     * their own. The form posts to the regular password update route.
     */
    public function edit(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->must_change_password) {
            return to_route('dashboard');
        }

        return Inertia::render('auth/ChangePassword', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }
}
