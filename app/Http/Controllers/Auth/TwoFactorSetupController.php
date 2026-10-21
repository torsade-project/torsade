<?php

// torsade/app/Http/Controllers/Auth/TwoFactorSetupController.php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class TwoFactorSetupController extends Controller
{
    /**
     * Show the page where a user whose account requires two-factor
     * authentication configures it, then saves their recovery codes. The
     * page drives the same endpoints as the security settings.
     */
    public function show(TwoFactorAuthenticationRequest $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user->two_factor_required) {
            return to_route('security.edit');
        }

        // Drops a setup that was started and never confirmed.
        $request->ensureStateIsValid();

        return Inertia::render('auth/TwoFactorSetup', [
            'twoFactorEnabled' => $user->hasEnabledTwoFactorAuthentication(),
            'requiresConfirmation' => Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm'),
        ]);
    }
}
