<?php

// torsade/tests/Feature/Auth/PasswordChangeSessionsTest.php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * When a user changes their own password, their other sessions are closed in
 * case the old password was known to someone else, and the session they are
 * using stays open. Sessions are real here: database driver and cookie.
 */
class PasswordChangeSessionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
    }

    private function openSessionFor(User $user, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '192.0.2.10',
            'user_agent' => 'Another device',
            'payload' => base64_encode('{}'),
            'last_activity' => time(),
        ]);
    }

    /**
     * Log in through the form and return the identifier of the new session.
     */
    private function logIn(User $user, string $password = 'password'): string
    {
        $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => $password]);

        $this->assertAuthenticatedAs($user);

        return $this->sessionIdFrom($response);
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function sessionIdFrom(TestResponse $response): string
    {
        $cookie = $response->getCookie((string) config('session.cookie'));

        $this->assertNotNull($cookie, 'No session cookie was issued.');

        return (string) $cookie->getValue();
    }

    /**
     * Make the next request behave like one from a browser holding the given
     * session cookie. In production every request runs in a fresh process;
     * here the guard, the session store, its handler and the redirector that
     * flashes into it would otherwise carry the state of the previous request
     * over to the next "browser".
     */
    private function asBrowserWith(string $sessionId): static
    {
        Auth::forgetGuards();

        foreach (['session', 'session.store', 'redirect', StartSession::class] as $service) {
            $this->app->forgetInstance($service);
        }

        return $this->withCookie((string) config('session.cookie'), $sessionId);
    }

    public function test_changing_the_password_closes_the_other_sessions_and_keeps_the_current_one(): void
    {
        $user = User::factory()->create();
        $bystander = User::factory()->create();

        $current = $this->logIn($user);

        $this->openSessionFor($user, 'session-on-another-device');
        $this->openSessionFor($user, 'session-in-another-browser');
        $this->openSessionFor($bystander, 'session-of-someone-else');

        $previousToken = $user->refresh()->getRememberToken();

        $this->asBrowserWith($current)
            ->from(route('security.edit'))
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'a-brand-new-password',
                'password_confirmation' => 'a-brand-new-password',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('security.edit'));

        // The other sessions of the user are gone, and only theirs.
        $this->assertDatabaseMissing('sessions', ['id' => 'session-on-another-device']);
        $this->assertDatabaseMissing('sessions', ['id' => 'session-in-another-browser']);
        $this->assertDatabaseHas('sessions', ['id' => 'session-of-someone-else']);

        // The session in use is still there and still signed in.
        $this->assertDatabaseHas('sessions', ['id' => $current, 'user_id' => $user->id]);
        $this->assertSame(1, DB::table('sessions')->where('user_id', $user->id)->count());

        $this->asBrowserWith($current)->get(route('dashboard'))->assertOk();
        $this->assertAuthenticatedAs($user);

        // "Remember me" cookies of the other devices stop working too.
        $this->assertNotSame($previousToken, $user->refresh()->getRememberToken());
    }

    public function test_a_closed_session_is_signed_out(): void
    {
        $user = User::factory()->create();

        // Two devices signed in, then the password is changed from one.
        $otherDevice = $this->logIn($user);
        $current = $this->asBrowserWith('')->logIn($user);

        $this->assertNotSame($otherDevice, $current);
        $this->assertSame(2, DB::table('sessions')->where('user_id', $user->id)->count());

        $this->asBrowserWith($current)->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertSessionHasNoErrors();

        $this->asBrowserWith($otherDevice)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->asBrowserWith($current)->get(route('dashboard'))->assertOk();
    }

    public function test_the_forced_password_change_closes_the_other_sessions_too(): void
    {
        $user = User::factory()->mustChangePassword()->create();

        $current = $this->logIn($user);

        $this->openSessionFor($user, 'session-on-another-device');

        $previousToken = $user->refresh()->getRememberToken();

        $this->asBrowserWith($current)
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'a-brand-new-password',
                'password_confirmation' => 'a-brand-new-password',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('sessions', ['id' => 'session-on-another-device']);
        $this->assertDatabaseHas('sessions', ['id' => $current, 'user_id' => $user->id]);
        $this->assertNotSame($previousToken, $user->refresh()->getRememberToken());

        $this->asBrowserWith($current)->get(route('dashboard'))->assertOk();
    }

    public function test_a_refused_password_change_closes_nothing(): void
    {
        $user = User::factory()->create();

        $current = $this->logIn($user);

        $this->openSessionFor($user, 'session-on-another-device');

        $previousToken = $user->refresh()->getRememberToken();

        $this->asBrowserWith($current)
            ->from(route('security.edit'))
            ->put(route('user-password.update'), [
                'current_password' => 'not-my-password',
                'password' => 'a-brand-new-password',
                'password_confirmation' => 'a-brand-new-password',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertDatabaseHas('sessions', ['id' => 'session-on-another-device']);
        $this->assertSame($previousToken, $user->refresh()->getRememberToken());
    }
}
