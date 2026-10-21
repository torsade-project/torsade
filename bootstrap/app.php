<?php

// torsade/bootstrap/app.php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureTwoFactorIsConfigured;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        // The health check stands outside of the "web" group: it must
        // answer without a session, a cookie or the database behind them.
        then: function (): void {
            Route::get('/up', HealthController::class)->name('health');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // The outermost middleware: it runs before the trusted hosts check,
        // the maintenance mode and every route group, so that error pages
        // (403, 404, 405, 419, 429, 500, 503) and the maintenance answer
        // carry the Content-Security-Policy and the other headers.
        $middleware->prepend(SecurityHeaders::class);

        // In production, a request whose Host header does not match the
        // host of APP_URL (without its subdomains) nor TRUSTED_HOSTS is
        // answered with a 4xx without detail. The framework turns the
        // check off in local and testing. The hosts are read from the
        // configuration on each request (closure), so that a cached
        // configuration still holds them. Each one is a pattern for the
        // framework: quoted and anchored, it matches that exact host, and
        // neither "www." before it nor anything around it.
        $middleware->trustHosts(
            at: fn (): array => array_map(
                fn (string $host): string => '^'.preg_quote($host).'$',
                array_values(array_filter([
                    (string) parse_url((string) config('app.url'), PHP_URL_HOST),
                    ...array_map(strval(...), (array) config('security.trusted_hosts', [])),
                ])),
            ),
            subdomains: false,
        );

        // The headers through which a trusted proxy announces the real
        // client address and scheme. The proxies themselves are read by the
        // middleware from config/trustedproxy.php on each request: none by
        // default, and the headers are then ignored.
        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // During a maintenance the health check still answers by itself,
        // in plain text, instead of the maintenance page: it says that the
        // application is unavailable.
        $middleware->preventRequestsDuringMaintenance(except: ['up']);

        $middleware->web(append: [
            // On every request of the web group, including the routes that
            // Fortify registers: account status first, then the temporary
            // password, then a required two-factor setup, before any page
            // is rendered.
            EnsureAccountIsActive::class,
            EnsurePasswordIsChanged::class,
            EnsureTwoFactorIsConfigured::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
