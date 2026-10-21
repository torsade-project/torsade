<?php

// torsade/tests/Feature/Auth/TwoFactorChallengeTest.php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Features;
use Tests\TestCase;

class TwoFactorChallengeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
    }

    public function test_two_factor_challenge_redirects_to_login_when_not_authenticated(): void
    {
        $response = $this->get(route('two-factor.login'));

        $response->assertRedirect(route('login'));
    }

    public function test_wrong_codes_are_rate_limited_at_login(): void
    {
        // A real secret, so that each code is verified and not just rejected.
        $user = User::factory()->withTwoFactor()->create([
            'two_factor_secret' => encrypt(app(TwoFactorAuthenticationProvider::class)->generateSecretKey()),
        ]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        foreach (range(1, 5) as $attempt) {
            $this->post(route('two-factor.login.store'), ['code' => '000000'])
                ->assertRedirect(route('two-factor.login'));
        }

        // Five wrong codes in a minute for this account: the sixth attempt is
        // not even checked.
        $this->post(route('two-factor.login.store'), ['code' => '000000'])
            ->assertRedirect(route('two-factor.login'))
            ->assertSessionHasErrors('code');

        $this->assertGuest();

        // A new login attempt does not reset the counter: it is kept per account.
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->post(route('two-factor.login.store'), ['code' => '000000'])->assertSessionHasErrors('code');

        $this->travel(61)->seconds();

        $this->post(route('two-factor.login.store'), ['code' => '000000'])
            ->assertRedirect(route('two-factor.login'));
    }

    public function test_beyond_the_limit_the_message_is_shown_in_the_form(): void
    {
        $user = User::factory()->withTwoFactor()->create([
            'two_factor_secret' => encrypt(app(TwoFactorAuthenticationProvider::class)->generateSecretKey()),
        ]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        foreach (range(1, 5) as $attempt) {
            $this->post(route('two-factor.login.store'), ['code' => '000000']);
        }

        // Not a bare "429" error page: back to the form, with the same
        // message as a locked login, under the field that was filled in.
        $this->post(route('two-factor.login.store'), ['code' => '000000'])
            ->assertRedirect(route('two-factor.login'));

        // The browser follows the redirect: the form is still there, and it
        // receives the message for its field.
        $form = $this->get(route('two-factor.login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/TwoFactorChallenge'));

        $errors = (array) $form->inertiaProps('errors');

        $this->assertSame(['code'], array_keys($errors));
        $this->assertMatchesRegularExpression(
            '/^Trop de tentatives de connexion\. Veuillez réessayer dans \d+ secondes\.$/',
            $errors['code'],
        );

        $this->assertGuest();
    }

    public function test_beyond_the_limit_a_recovery_code_gets_the_message_on_its_own_field(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        foreach (range(1, 5) as $attempt) {
            $this->post(route('two-factor.login.store'), ['recovery_code' => 'wrong-code']);
        }

        // Even the right code is refused once the limit is reached.
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])
            ->assertRedirect(route('two-factor.login'))
            ->assertSessionHasErrors('recovery_code')
            ->assertSessionDoesntHaveErrors('code');

        $this->assertGuest();
    }

    public function test_beyond_the_limit_a_json_client_gets_a_429_with_the_message(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        foreach (range(1, 5) as $attempt) {
            $this->postJson(route('two-factor.login.store'), ['code' => '000000']);
        }

        $response = $this->postJson(route('two-factor.login.store'), ['code' => '000000']);

        $response->assertTooManyRequests()->assertHeader('Retry-After');

        $this->assertStringStartsWith('Trop de tentatives de connexion.', (string) $response->json('message'));
    }

    public function test_two_factor_challenge_can_be_rendered(): void
    {
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);

        $user = User::factory()->withTwoFactor()->create();

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->get(route('two-factor.login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/TwoFactorChallenge'),
            );
    }
}
