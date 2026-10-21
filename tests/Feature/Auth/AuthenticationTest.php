<?php

// torsade/tests/Feature/Auth/AuthenticationTest.php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;
use Laravel\Fortify\Features;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The message shown under the e-mail field after the last request. The
     * session is shared by every response of a test, so it is read at once.
     */
    private function emailError(): string
    {
        $errors = session('errors');

        // Once saved, the JSON session keeps the error bags as plain arrays.
        return $errors instanceof ViewErrorBag
            ? $errors->first('email')
            : (string) data_get($errors, 'default.messages.email.0', '');
    }

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_with_two_factor_enabled_are_redirected_to_two_factor_challenge(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);

        $user = User::factory()->withTwoFactor()->create();

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('two-factor.login'));
        $response->assertSessionHas('login.id', $user->id);
        $this->assertGuest();
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_the_failure_message_does_not_reveal_whether_the_email_exists(): void
    {
        $user = User::factory()->create();

        $knownEmail = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $knownMessage = $this->emailError();

        $unknownEmail = $this->post(route('login.store'), [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ]);

        $unknownMessage = $this->emailError();

        $knownEmail->assertSessionHasErrors('email');
        $unknownEmail->assertSessionHasErrors('email');

        $this->assertNotSame('', $knownMessage);
        $this->assertSame($knownMessage, $unknownMessage);
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect(route('home'));

        $this->assertGuest();
    }

    public function test_login_is_locked_after_five_failures_in_a_minute(): void
    {
        $user = User::factory()->create();

        $failureMessage = null;

        foreach (range(1, 5) as $attempt) {
            $this->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');

            $failureMessage = $this->emailError();
        }

        // The sixth attempt is refused even though the password is right.
        $locked = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $locked->assertSessionHasErrors('email');
        $this->assertGuest();

        // The user is told about the lock, not that the credentials are wrong.
        $this->assertNotSame($failureMessage, $this->emailError());
    }

    public function test_the_lock_is_lifted_after_a_minute(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $attempt) {
            $this->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);
        }

        $this->travel(61)->seconds();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_lock_only_applies_to_the_targeted_email_address(): void
    {
        $target = User::factory()->create();
        $other = User::factory()->create();

        foreach (range(1, 5) as $attempt) {
            $this->post(route('login.store'), [
                'email' => $target->email,
                'password' => 'wrong-password',
            ]);
        }

        $this->post(route('login.store'), [
            'email' => $other->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($other);
    }
}
