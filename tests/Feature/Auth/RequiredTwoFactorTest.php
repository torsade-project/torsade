<?php

// torsade/tests/Feature/Auth/RequiredTwoFactorTest.php

namespace Tests\Feature\Auth;

use App\Enums\ActivityEvent;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\BuildsRouteUris;
use Tests\TestCase;

/**
 * When an administrator requires two-factor authentication on an account,
 * its user can do nothing but configure it, and can never disable it.
 */
class RequiredTwoFactorTest extends TestCase
{
    use BuildsRouteUris, RefreshDatabase;

    /**
     * The only routes that stay reachable while the setup is pending.
     */
    private const SETUP_ROUTES = [
        'two-factor.setup',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'logout',
    ];

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

    /**
     * A user whose two-factor authentication is active, with a real secret.
     */
    private function userWithWorkingTwoFactor(bool $required): User
    {
        return User::factory()
            ->withTwoFactor()
            ->state(['two_factor_required' => $required])
            ->create([
                'two_factor_secret' => encrypt(app(TwoFactorAuthenticationProvider::class)->generateSecretKey()),
            ]);
    }

    // --- Pending setup ------------------------------------------------------

    public function test_every_authenticated_route_redirects_to_the_setup_page(): void
    {
        // An administrator, so that no route is refused for another reason.
        $user = User::factory()->admin()->twoFactorRequired()->create();
        $other = User::factory()->create();

        $checked = 0;

        foreach ($this->authenticatedRoutes() as $route) {
            if (in_array($route->getName(), self::SETUP_ROUTES, true)) {
                continue;
            }

            $uri = $this->uriOf($route, $other);

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $response = $this->actingAs($user)
                    ->withSession(['auth.password_confirmed_at' => time()])
                    ->call($method, $uri);

                // Disabling is refused outright rather than redirected.
                $route->getName() === 'two-factor.disable'
                    ? $response->assertForbidden()
                    : $response->assertRedirect(route('two-factor.setup'));

                $checked++;
            }
        }

        // Dashboard, settings, user administration, activity log, network
        // documentation…
        $this->assertGreaterThan(15, $checked);
        $this->assertFalse($other->refresh()->two_factor_required);
    }

    public function test_a_json_request_is_refused_until_the_setup_is_done(): void
    {
        $user = User::factory()->admin()->twoFactorRequired()->create();

        $this->actingAs($user)->postJson(route('users.store'), [
            'name' => 'Camille Martin',
            'email' => 'camille@example.com',
            'role' => 'reader',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'camille@example.com']);
    }

    public function test_the_forced_password_change_comes_first(): void
    {
        $user = User::factory()->mustChangePassword()->twoFactorRequired()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('password.change'));
        $this->actingAs($user)->get(route('two-factor.setup'))->assertRedirect(route('password.change'));
        $this->actingAs($user)->get(route('password.change'))->assertOk();

        $this->actingAs($user)
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'a-brand-new-password',
                'password_confirmation' => 'a-brand-new-password',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard'));

        // The password is changed: the two-factor setup is now what is due.
        $this->get(route('dashboard'))->assertRedirect(route('two-factor.setup'));
        $this->get(route('password.change'))->assertRedirect(route('two-factor.setup'));

        // The password was typed a moment ago: it is not asked a third time.
        $this->get(route('two-factor.setup'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/TwoFactorSetup'));
    }

    public function test_the_setup_page_asks_for_the_password_first(): void
    {
        $user = User::factory()->twoFactorRequired()->create();

        $this->actingAs($user)
            ->get(route('two-factor.setup'))
            ->assertRedirect(route('password.confirm'));

        $this->actingAs($user)->get(route('password.confirm'))->assertOk();
    }

    public function test_the_setup_page_is_displayed(): void
    {
        $user = User::factory()->twoFactorRequired()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('two-factor.setup'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/TwoFactorSetup')
                ->where('twoFactorEnabled', false)
                ->where('requiresConfirmation', true),
            );
    }

    public function test_the_setup_page_is_only_for_accounts_that_require_it(): void
    {
        $this->actingAs(User::factory()->create())
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('two-factor.setup'))
            ->assertRedirect(route('security.edit'));

        $this->post(route('logout'));

        $this->get(route('two-factor.setup'))->assertRedirect(route('login'));
    }

    public function test_the_user_can_still_log_out(): void
    {
        $user = User::factory()->twoFactorRequired()->create();

        $this->actingAs($user)->post(route('logout'))->assertRedirect(route('home'));

        $this->assertGuest();
    }

    public function test_logging_in_leads_to_the_setup_page(): void
    {
        $user = User::factory()->twoFactorRequired()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
        $this->get(route('dashboard'))->assertRedirect(route('two-factor.setup'));
    }

    // --- Setup, from start to finish ---------------------------------------

    public function test_access_is_normal_once_two_factor_authentication_is_set_up(): void
    {
        $user = User::factory()->twoFactorRequired()->create();
        $confirmed = ['auth.password_confirmed_at' => time()];

        $this->actingAs($user)->withSession($confirmed)->post(route('two-factor.enable'))->assertRedirect();

        $user->refresh();

        $this->assertNotNull($user->two_factor_secret);
        $this->assertTrue($user->mustSetUpTwoFactor(), 'An unconfirmed secret is not a finished setup.');
        $this->actingAs($user)->withSession($confirmed)->get(route('dashboard'))->assertRedirect(route('two-factor.setup'));

        // What the pending page needs: the QR code and the manual key.
        $this->actingAs($user)->withSession($confirmed)->getJson(route('two-factor.qr-code'))->assertOk();
        $this->actingAs($user)->withSession($confirmed)->getJson(route('two-factor.secret-key'))->assertOk();

        $code = app(Google2FA::class)->getCurrentOtp(decrypt($user->two_factor_secret));

        $this->actingAs($user)
            ->withSession($confirmed)
            ->post(route('two-factor.confirm'), ['code' => $code])
            ->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertTrue($user->hasEnabledTwoFactorAuthentication());
        $this->assertFalse($user->mustSetUpTwoFactor());

        $this->actingAs($user)->withSession($confirmed)->get(route('dashboard'))->assertOk();
        $this->actingAs($user)->withSession($confirmed)->get(route('profile.edit'))->assertOk();

        // The page then shows the recovery codes before letting the user go.
        $this->actingAs($user)
            ->withSession($confirmed)
            ->get(route('two-factor.setup'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('twoFactorEnabled', true));

        $this->actingAs($user)->withSession($confirmed)->getJson(route('two-factor.recovery-codes'))->assertOk();

        $entry = Activity::query()->where('event', ActivityEvent::TwoFactorEnabled->value)->firstOrFail();

        $this->assertTrue($user->is($entry->causer));
        $this->assertTrue($user->is($entry->subject));
    }

    public function test_generating_a_secret_without_confirming_it_is_not_logged_as_an_activation(): void
    {
        $user = User::factory()->twoFactorRequired()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('two-factor.enable'));

        $this->assertSame(0, Activity::query()->where('event', ActivityEvent::TwoFactorEnabled->value)->count());
    }

    // --- Disabling ----------------------------------------------------------

    public function test_a_user_cannot_disable_a_required_two_factor_authentication(): void
    {
        $user = $this->userWithWorkingTwoFactor(required: true);
        $secret = $user->two_factor_secret;

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->delete(route('two-factor.disable'))
            ->assertForbidden();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->deleteJson(route('two-factor.disable'))
            ->assertForbidden();

        $user->refresh();

        $this->assertSame($secret, $user->two_factor_secret);
        $this->assertTrue($user->hasEnabledTwoFactorAuthentication());
        $this->assertSame(0, Activity::query()->where('event', ActivityEvent::TwoFactorDisabled->value)->count());
    }

    public function test_the_disable_button_is_not_offered_when_it_is_required(): void
    {
        $user = $this->userWithWorkingTwoFactor(required: true);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('twoFactorEnabled', true)
                ->where('twoFactorRequired', true),
            );

        $component = (string) file_get_contents(resource_path('js/components/ManageTwoFactor.vue'));

        // The form that disables it is the alternative to the notice shown
        // when it is required: both are never rendered together.
        $this->assertMatchesRegularExpression(
            '/v-if="twoFactorRequired".*?<div v-else[^>]*>\s*<Form v-bind="disable\.form\(\)"/s',
            $component,
        );
    }

    public function test_a_user_can_disable_an_optional_two_factor_authentication(): void
    {
        $user = $this->userWithWorkingTwoFactor(required: false);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('twoFactorRequired', false));

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->delete(route('two-factor.disable'))
            ->assertSessionHasNoErrors();

        $this->assertFalse($user->refresh()->hasEnabledTwoFactorAuthentication());

        $entry = Activity::query()->where('event', ActivityEvent::TwoFactorDisabled->value)->firstOrFail();

        $this->assertTrue($user->is($entry->causer));
    }

    public function test_an_account_with_required_two_factor_already_set_up_is_not_disturbed(): void
    {
        $user = $this->userWithWorkingTwoFactor(required: true);

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->actingAs($user)->get(route('profile.edit'))->assertOk();
    }

    // --- After a reset ------------------------------------------------------

    public function test_after_a_reset_the_user_sets_it_up_again_at_the_next_login(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->userWithWorkingTwoFactor(required: true);

        $this->actingAs($admin)->post(route('users.two-factor.reset', $user));
        $this->post(route('logout'));

        // No second factor is asked any more: the password is enough to log
        // in, and the setup page is the only place the user can go.
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->get(route('dashboard'))->assertRedirect(route('two-factor.setup'));
    }
}
