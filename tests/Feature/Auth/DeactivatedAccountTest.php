<?php

// torsade/tests/Feature/Auth/DeactivatedAccountTest.php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class DeactivatedAccountTest extends TestCase
{
    use RefreshDatabase;

    private function emailError(): string
    {
        $errors = session('errors');

        // Once saved, the JSON session keeps the error bags as plain arrays.
        return $errors instanceof ViewErrorBag
            ? $errors->first('email')
            : (string) data_get($errors, 'default.messages.email.0', '');
    }

    private function openSessionFor(User $user, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '192.0.2.10',
            'user_agent' => 'Test',
            'payload' => base64_encode('{}'),
            'last_activity' => time(),
        ]);
    }

    public function test_a_deactivated_account_cannot_log_in(): void
    {
        $user = User::factory()->inactive()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_the_refusal_does_not_reveal_that_the_account_is_deactivated(): void
    {
        $user = User::factory()->inactive()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $deactivated = $this->emailError();

        $this->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'password']);
        $unknown = $this->emailError();

        $this->assertNotSame('', $deactivated);
        $this->assertSame($unknown, $deactivated);
    }

    public function test_a_deactivated_account_never_reaches_the_two_factor_challenge(): void
    {
        $user = User::factory()->withTwoFactor()->inactive()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $response->assertSessionMissing('login.id');
        $this->assertGuest();
    }

    public function test_a_signed_in_user_is_signed_out_once_the_account_is_deactivated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        // Deactivated by an administrator between two requests.
        $user->is_active = false;
        $user->save();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_deactivated_account_gets_a_401_on_a_json_request(): void
    {
        $user = User::factory()->inactive()->create();

        $this->actingAs($user)->getJson(route('dashboard'))->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_deactivation_deletes_the_sessions_of_the_account(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->openSessionFor($user, 'session-of-the-user-1');
        $this->openSessionFor($user, 'session-of-the-user-2');
        $this->openSessionFor($admin, 'session-of-the-admin');

        $this->actingAs($admin)
            ->post(route('users.deactivate', $user))
            ->assertRedirect(route('users.index'));

        $this->assertFalse($user->refresh()->is_active);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'session-of-the-admin']);
    }

    public function test_deactivation_regenerates_the_remember_me_token(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $previousToken = $user->getRememberToken();

        $this->actingAs($admin)->post(route('users.deactivate', $user));

        $newToken = $user->refresh()->getRememberToken();

        $this->assertNotEmpty($newToken);
        $this->assertNotSame($previousToken, $newToken);
    }

    public function test_a_reactivated_account_can_log_in_again(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->inactive()->create();

        $this->actingAs($admin)
            ->post(route('users.activate', $user))
            ->assertRedirect(route('users.index'));

        $this->assertTrue($user->refresh()->is_active);

        $this->post(route('logout'));

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
    }
}
