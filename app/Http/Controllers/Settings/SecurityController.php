<?php

// torsade/app/Http/Controllers/Settings/SecurityController.php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use App\Services\UserAccounts;
use App\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class SecurityController extends Controller
{
    /**
     * Show the user's security settings page.
     */
    public function edit(TwoFactorAuthenticationRequest $request): Response
    {
        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();

            $props['twoFactorEnabled'] = $request->user()->hasEnabledTwoFactorAuthentication();
            $props['twoFactorRequired'] = $request->user()->two_factor_required;
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        return Inertia::render('settings/Security', $props);
    }

    /**
     * Update the user's password. This is also what lifts a forced change.
     */
    public function update(PasswordUpdateRequest $request, UserAccounts $accounts): RedirectResponse
    {
        $user = $request->user();
        $wasForced = $user->must_change_password;

        $accounts->changeOwnPassword(
            $user,
            $request->validated('password'),
            Actor::fromRequest($request),
            $request->session()->getId(),
        );

        // The current password has just been checked: asking for it again on
        // the next sensitive page, such as a required two-factor setup, would
        // only be noise.
        $request->session()->passwordConfirmed();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated.')]);

        return $wasForced ? to_route('dashboard') : back();
    }
}
