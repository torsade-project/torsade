<?php

// torsade/tests/Feature/Console/ResetPasswordCommandTest.php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ResetPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    private function passwordIn(string $output): string
    {
        $this->assertMatchesRegularExpression('/Mot de passe temporaire\x{00A0}: (\S+)/u', $output);

        preg_match('/Mot de passe temporaire\x{00A0}: (\S+)/u', $output, $matches);

        return $matches[1];
    }

    public function test_it_gives_the_account_a_temporary_password_shown_once(): void
    {
        $user = User::factory()->create(['email' => 'camille@example.com']);

        $exitCode = Artisan::call('torsade:reset-password', ['email' => 'Camille@Example.com']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);

        $password = $this->passwordIn($output);
        $user->refresh();

        $this->assertGreaterThanOrEqual(20, strlen($password));
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertSame(1, substr_count($output, $password), 'The password is shown exactly once.');
    }

    public function test_it_deletes_the_sessions_of_the_account(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $previousToken = $user->getRememberToken();

        foreach ([[$user, 'session-of-the-user'], [$other, 'session-of-another-user']] as [$owner, $id]) {
            DB::table('sessions')->insert([
                'id' => $id,
                'user_id' => $owner->id,
                'ip_address' => '192.0.2.10',
                'user_agent' => 'Test',
                'payload' => base64_encode('{}'),
                'last_activity' => time(),
            ]);
        }

        Artisan::call('torsade:reset-password', ['email' => $user->email]);

        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'session-of-another-user']);
        $this->assertNotSame($previousToken, $user->refresh()->getRememberToken());
    }

    public function test_it_fails_for_an_unknown_email(): void
    {
        $exitCode = Artisan::call('torsade:reset-password', ['email' => 'nobody@example.com']);
        $output = Artisan::output();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString("Aucun compte n'utilise cette adresse e-mail", $output);
        $this->assertStringNotContainsString('Mot de passe temporaire', $output);
    }

    public function test_it_does_not_reactivate_a_deactivated_account(): void
    {
        $user = User::factory()->inactive()->create();

        $exitCode = Artisan::call('torsade:reset-password', ['email' => $user->email]);

        $this->assertSame(0, $exitCode);
        $this->assertFalse($user->refresh()->is_active);
        $this->assertStringContainsString('Ce compte est désactivé', Artisan::output());
    }

    public function test_the_user_can_log_in_with_the_temporary_password_and_must_change_it(): void
    {
        $user = User::factory()->create();

        Artisan::call('torsade:reset-password', ['email' => $user->email]);

        $password = $this->passwordIn(Artisan::output());

        $this->post(route('login.store'), ['email' => $user->email, 'password' => $password]);

        $this->assertAuthenticatedAs($user);
        $this->get(route('dashboard'))->assertRedirect(route('password.change'));
    }
}
