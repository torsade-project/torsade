<?php

// torsade/tests/Feature/Auth/ForcedPasswordChangeTest.php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use App\Services\UserAccounts;
use App\Support\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsRouteUris;
use Tests\TestCase;

class ForcedPasswordChangeTest extends TestCase
{
    use BuildsRouteUris, RefreshDatabase;

    /**
     * The only routes that stay reachable with a temporary password.
     */
    private const ALLOWED = ['password.change', 'user-password.update', 'logout'];

    /**
     * Every registered route that requires authentication.
     *
     * @return list<RoutingRoute>
     */
    private function authenticatedRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn (RoutingRoute $route): bool => array_any(
                $route->gatherMiddleware(),
                fn (mixed $middleware): bool => is_string($middleware) && preg_match('/^auth(:|$)/', $middleware) === 1,
            ),
        ));
    }

    public function test_every_authenticated_route_redirects_to_the_password_change(): void
    {
        // An administrator, so that no route is refused for another reason.
        $user = User::factory()->admin()->mustChangePassword()->create();
        $other = User::factory()->create();

        $checked = 0;

        foreach ($this->authenticatedRoutes() as $route) {
            if (in_array($route->getName(), self::ALLOWED, true)) {
                continue;
            }

            $uri = $this->uriOf($route, $other);

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->actingAs($user)
                    ->call($method, $uri)
                    ->assertRedirect(route('password.change'));

                $checked++;
            }
        }

        // Dashboard, settings, user administration, activity log, two-factor,
        // network documentation…
        $this->assertGreaterThan(15, $checked);
        $this->assertTrue($user->refresh()->must_change_password);
    }

    public function test_the_home_page_leads_to_the_password_change(): void
    {
        $user = User::factory()->mustChangePassword()->create();

        $this->actingAs($user)->get('/')->assertRedirect(route('password.change'));
    }

    public function test_a_json_request_is_refused_until_the_password_is_changed(): void
    {
        $user = User::factory()->admin()->mustChangePassword()->create();

        $this->actingAs($user)->postJson(route('users.store'), [
            'name' => 'Camille Martin',
            'email' => 'camille@example.com',
            'role' => 'reader',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'camille@example.com']);
    }

    public function test_the_password_change_page_is_displayed(): void
    {
        $user = User::factory()->mustChangePassword()->create();

        $this->actingAs($user)
            ->get(route('password.change'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/ChangePassword'));
    }

    public function test_the_password_change_page_is_only_for_temporary_passwords(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('password.change'))
            ->assertRedirect(route('dashboard'));

        $this->post(route('logout'));

        $this->get(route('password.change'))->assertRedirect(route('login'));
    }

    public function test_the_user_can_still_log_out(): void
    {
        $user = User::factory()->mustChangePassword()->create();

        $this->actingAs($user)->post(route('logout'))->assertRedirect(route('home'));

        $this->assertGuest();
    }

    public function test_access_is_normal_once_the_password_is_changed(): void
    {
        $user = User::factory()->mustChangePassword()->create();

        $this->actingAs($user)
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'a-brand-new-password',
                'password_confirmation' => 'a-brand-new-password',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $user->refresh();

        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('a-brand-new-password', $user->password));

        $this->get(route('dashboard'))->assertOk();
        $this->get(route('profile.edit'))->assertOk();
    }

    public function test_the_new_password_must_differ_from_the_temporary_one(): void
    {
        $user = User::factory()->mustChangePassword()->create([
            'password' => 'the-temporary-password',
        ]);

        $this->actingAs($user)
            ->from(route('password.change'))
            ->put(route('user-password.update'), [
                'current_password' => 'the-temporary-password',
                'password' => 'the-temporary-password',
                'password_confirmation' => 'the-temporary-password',
            ])
            ->assertSessionHasErrors('password')
            ->assertRedirect(route('password.change'));

        $this->assertTrue($user->refresh()->must_change_password);
        $this->get(route('dashboard'))->assertRedirect(route('password.change'));
    }

    public function test_the_temporary_password_is_required_to_choose_a_new_one(): void
    {
        $user = User::factory()->mustChangePassword()->create();

        $this->actingAs($user)
            ->from(route('password.change'))
            ->put(route('user-password.update'), [
                'current_password' => 'not-the-temporary-password',
                'password' => 'a-brand-new-password',
                'password_confirmation' => 'a-brand-new-password',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue($user->refresh()->must_change_password);
    }

    public function test_first_login_with_a_temporary_password_from_start_to_finish(): void
    {
        [$user, $temporaryPassword] = app(UserAccounts::class)->create(
            'Camille Martin',
            'camille@example.com',
            Role::Editor,
            Actor::console(),
        );

        $this->post(route('login.store'), [
            'email' => 'camille@example.com',
            'password' => $temporaryPassword,
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);

        $this->get(route('dashboard'))->assertRedirect(route('password.change'));

        $this->put(route('user-password.update'), [
            'current_password' => $temporaryPassword,
            'password' => 'my-own-long-password',
            'password_confirmation' => 'my-own-long-password',
        ])->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))->assertOk();
    }
}
