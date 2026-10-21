<?php

// torsade/tests/Feature/RouteInventoryTest.php

namespace Tests\Feature;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every route must be accounted for. A route is acceptable when it is public
 * by design, behind a gate or a policy, or listed as acting only on the
 * account of the signed-in user. Anything else makes these tests fail.
 */
class RouteInventoryTest extends TestCase
{
    /**
     * Routes reachable without being signed in, each for a stated reason.
     */
    private const PUBLIC_ROUTES = [
        // Redirects to the login form or to the dashboard.
        'GET /',
        // The login form and its submission.
        'GET login',
        'POST login',
        // Second step of a login, bound to the session of the first step.
        'GET two-factor-challenge',
        'POST two-factor-challenge',
        // Health check: one word of plain text that says whether the
        // database is reachable, nothing else.
        'GET up',
    ];

    /**
     * Routes that only read or change the account of the signed-in user:
     * authentication is their whole authorisation, there is nobody else's
     * data to protect behind them.
     */
    private const OWN_ACCOUNT_ROUTES = [
        'POST logout',
        // Settings of the account.
        'GET settings',
        'GET settings/profile',
        'PATCH settings/profile',
        'GET settings/security',
        'PUT settings/password',
        'GET settings/appearance',
        // Pages a user is sent to before anything else.
        'GET change-password',
        'GET two-factor/setup',
        // Confirmation of one's own password before a sensitive change.
        'GET user/confirm-password',
        'POST user/confirm-password',
        'GET user/confirmed-password-status',
        // One's own two-factor authentication.
        'POST user/two-factor-authentication',
        'DELETE user/two-factor-authentication',
        'POST user/confirmed-two-factor-authentication',
        'GET user/two-factor-qr-code',
        'GET user/two-factor-secret-key',
        'GET user/two-factor-recovery-codes',
        'POST user/two-factor-recovery-codes',
    ];

    /**
     * Administration routes and the gate each one must carry.
     */
    private const GATED_PREFIXES = [
        'users' => 'can:manage-users',
        'activity-log' => 'can:view-activity-log',
    ];

    /**
     * @return list<RoutingRoute>
     */
    private function routes(): array
    {
        return Route::getRoutes()->getRoutes();
    }

    /**
     * @return list<string>
     */
    private function signatures(RoutingRoute $route): array
    {
        return array_map(
            fn (string $method): string => $method.' '.$route->uri(),
            array_values(array_diff($route->methods(), ['HEAD'])),
        );
    }

    private function requiresAuthentication(RoutingRoute $route): bool
    {
        return array_any(
            $route->gatherMiddleware(),
            fn (mixed $middleware): bool => is_string($middleware) && preg_match('/^auth(:|$)/', $middleware) === 1,
        );
    }

    /**
     * Whether the route is behind a gate or a policy ("can" middleware).
     */
    private function isGated(RoutingRoute $route): bool
    {
        return array_any(
            $route->gatherMiddleware(),
            fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, 'can:'),
        );
    }

    /**
     * The routes that fit none of the three accepted cases.
     *
     * @return list<string>
     */
    private function unaccountedRoutes(): array
    {
        $unaccounted = [];

        foreach ($this->routes() as $route) {
            foreach ($this->signatures($route) as $signature) {
                $accepted = $this->requiresAuthentication($route)
                    ? $this->isGated($route) || in_array($signature, self::OWN_ACCOUNT_ROUTES, true)
                    : in_array($signature, self::PUBLIC_ROUTES, true);

                if (! $accepted) {
                    $unaccounted[] = $signature;
                }
            }
        }

        return $unaccounted;
    }

    public function test_every_route_is_public_by_design_gated_or_limited_to_the_own_account(): void
    {
        $this->assertSame([], $this->unaccountedRoutes());
    }

    public function test_an_authenticated_route_without_a_gate_is_reported(): void
    {
        Route::middleware(['web', 'auth'])->get('ungated-probe', fn () => 'reachable by any signed-in user');
        Route::middleware(['web', 'auth', 'can:view-network'])->get('gated-probe', fn () => 'behind a gate');

        $this->assertSame(['GET ungated-probe'], $this->unaccountedRoutes());
    }

    public function test_a_public_route_that_is_not_listed_is_reported(): void
    {
        Route::middleware(['web'])->get('public-probe', fn () => 'reachable by anyone');

        $this->assertSame(['GET public-probe'], $this->unaccountedRoutes());
    }

    public function test_the_lists_only_name_routes_that_exist(): void
    {
        $registered = [];

        foreach ($this->routes() as $route) {
            $registered = [...$registered, ...$this->signatures($route)];
        }

        $this->assertSame([], array_values(array_diff(self::PUBLIC_ROUTES, $registered)));
        $this->assertSame([], array_values(array_diff(self::OWN_ACCOUNT_ROUTES, $registered)));
    }

    public function test_own_account_routes_all_require_authentication(): void
    {
        foreach ($this->routes() as $route) {
            foreach ($this->signatures($route) as $signature) {
                if (in_array($signature, self::OWN_ACCOUNT_ROUTES, true)) {
                    $this->assertTrue($this->requiresAuthentication($route), "[{$signature}] does not require authentication.");
                }
            }
        }
    }

    public function test_the_dashboard_is_behind_the_view_network_gate(): void
    {
        $dashboard = Route::getRoutes()->getByName('dashboard');

        $this->assertNotNull($dashboard);
        $this->assertTrue($this->requiresAuthentication($dashboard));
        $this->assertContains('can:view-network', $dashboard->gatherMiddleware());
    }

    public function test_administration_routes_carry_their_gate(): void
    {
        $checked = 0;

        foreach ($this->routes() as $route) {
            foreach (self::GATED_PREFIXES as $prefix => $gate) {
                if ($route->uri() === $prefix || str_starts_with($route->uri(), $prefix.'/')) {
                    $this->assertContains($gate, $route->gatherMiddleware(), "[{$route->uri()}] is not behind [{$gate}].");
                    $this->assertTrue($this->requiresAuthentication($route));

                    $checked++;
                }
            }
        }

        // Eleven user administration routes and the activity log.
        $this->assertSame(12, $checked);
    }

    public function test_two_factor_administration_routes_refuse_the_own_account(): void
    {
        $checked = 0;

        foreach ($this->routes() as $route) {
            if (str_contains($route->uri(), '/two-factor/') && str_starts_with($route->uri(), 'users/')) {
                $this->assertContains('can:manage-user-two-factor,user', $route->gatherMiddleware());

                $checked++;
            }
        }

        $this->assertSame(3, $checked);
    }

    public function test_no_route_serves_files_from_storage(): void
    {
        foreach ($this->routes() as $route) {
            $this->assertStringStartsNotWith('storage', $route->uri());
        }

        $this->assertFalse(config('filesystems.disks.local.serve'));
        $this->get('/storage/anything.txt')->assertNotFound();
    }

    public function test_state_changing_routes_are_protected_against_csrf(): void
    {
        foreach ($this->routes() as $route) {
            if (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) === []) {
                continue;
            }

            // The "web" group holds the CSRF middleware: no route opts out.
            $this->assertContains('web', $route->gatherMiddleware(), "[{$route->uri()}] is outside the web group.");
            $this->assertSame([], $route->excludedMiddleware(), "[{$route->uri()}] excludes a middleware.");
        }
    }
}
