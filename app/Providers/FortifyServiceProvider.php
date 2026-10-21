<?php

// torsade/app/Providers/FortifyServiceProvider.php

namespace App\Providers;

use App\Actions\Auth\AuthenticateUser;
use App\Http\Responses\LockoutResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LockoutResponse as LockoutResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The message of a locked login agrees with the seconds left.
        $this->app->singleton(LockoutResponseContract::class, LockoutResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureAuthentication();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Credentials are checked by Torsade's own action, which also refuses
     * deactivated accounts. Fortify uses it for the password step of a
     * two-factor login as well.
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(fn (Request $request) => app(AuthenticateUser::class)($request));
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     *
     * Login attempts are not limited here: "fortify.limiters.login" is null so
     * that Fortify's own limiter applies. It counts failures only, per e-mail
     * address and IP address, and locks the pair after 5 failures in a minute.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)
                ->by($request->session()->get('login.id'))
                ->response(fn (Request $request, array $headers) => $this->twoFactorLockout($request, $headers));
        });
    }

    /**
     * Beyond the limit, the code is not even checked. The user is sent back
     * to the form with a message under the field they filled in, as for a
     * locked login, rather than to a bare error page.
     *
     * @param  array<string, mixed>  $headers
     */
    private function twoFactorLockout(Request $request, array $headers): Response
    {
        $seconds = (int) ($headers['Retry-After'] ?? 60);

        $message = trans_choice('auth.throttle', $seconds, [
            'seconds' => $seconds,
            'minutes' => (int) ceil($seconds / 60),
        ]);

        // A client that asked for JSON has no form to come back to.
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], Response::HTTP_TOO_MANY_REQUESTS, $headers);
        }

        return redirect()
            ->route('two-factor.login')
            ->withErrors([$request->filled('recovery_code') ? 'recovery_code' : 'code' => $message]);
    }
}
