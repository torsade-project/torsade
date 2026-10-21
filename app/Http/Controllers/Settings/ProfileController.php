<?php

// torsade/app/Http/Controllers/Settings/ProfileController.php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Services\UserAccounts;
use App\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(): Response
    {
        return Inertia::render('settings/Profile');
    }

    /**
     * Update the user's own name. The e-mail address is the login identifier
     * and can only be changed by an administrator.
     */
    public function update(ProfileUpdateRequest $request, UserAccounts $accounts): RedirectResponse
    {
        $accounts->rename($request->user(), $request->validated('name'), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }
}
