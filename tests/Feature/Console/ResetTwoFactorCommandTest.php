<?php

// torsade/tests/Feature/Console/ResetTwoFactorCommandTest.php

namespace Tests\Feature\Console;

use App\Enums\ActivityEvent;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResetTwoFactorCommandTest extends TestCase
{
    use RefreshDatabase;

    private function lastEntry(ActivityEvent $event): Activity
    {
        return Activity::query()->where('event', $event->value)->orderByDesc('id')->firstOrFail();
    }

    public function test_it_removes_the_two_factor_configuration_of_the_account(): void
    {
        $user = User::factory()->withTwoFactor()->create(['email' => 'camille@example.com']);

        $exitCode = Artisan::call('torsade:reset-two-factor', ['email' => 'Camille@Example.com']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Double authentification de camille@example.com réinitialisée', Artisan::output());

        $user->refresh();

        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());
    }

    public function test_it_closes_the_sessions_of_the_account(): void
    {
        $user = User::factory()->withTwoFactor()->create();
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

        Artisan::call('torsade:reset-two-factor', ['email' => $user->email]);

        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'session-of-another-user']);
        $this->assertNotSame($previousToken, $user->refresh()->getRememberToken());
    }

    public function test_it_is_logged_with_the_console_as_author(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        Artisan::call('torsade:reset-two-factor', ['email' => $user->email]);

        $command = $this->lastEntry(ActivityEvent::CommandExecuted);

        $this->assertNull($command->causer);
        $this->assertTrue($user->is($command->subject));
        $this->assertSame('console', $command->getProperty('source'));
        $this->assertSame('torsade:reset-two-factor', $command->getProperty('command'));
        $this->assertSame('success', $command->getProperty('outcome'));

        $reset = $this->lastEntry(ActivityEvent::TwoFactorReset);

        $this->assertNull($reset->causer);
        $this->assertTrue($user->is($reset->subject));
        $this->assertSame('console', $reset->getProperty('source'));
    }

    public function test_it_fails_with_a_clear_message_for_an_unknown_email(): void
    {
        $exitCode = Artisan::call('torsade:reset-two-factor', ['email' => 'nobody@example.com']);
        $output = Artisan::output();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString("Aucun compte n'utilise cette adresse e-mail", $output);

        $command = $this->lastEntry(ActivityEvent::CommandExecuted);

        $this->assertSame('unknown_account', $command->getProperty('outcome'));
        $this->assertSame(0, Activity::query()->where('event', ActivityEvent::TwoFactorReset->value)->count());

        // The address that was typed is not kept.
        $this->assertStringNotContainsString('nobody@example.com', json_encode(DB::table('activity_log')->get(), JSON_THROW_ON_ERROR));
    }

    public function test_it_rescues_the_only_administrator(): void
    {
        // Lost device and lost recovery codes: nobody can help from the interface.
        $admin = User::factory()->admin()->withTwoFactor()->twoFactorRequired()->create();

        $exitCode = Artisan::call('torsade:reset-two-factor', ['email' => $admin->email]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('elle devra être reconfigurée à la prochaine connexion', $output);

        $admin->refresh();

        $this->assertFalse($admin->hasEnabledTwoFactorAuthentication());
        $this->assertTrue($admin->two_factor_required);

        // The password alone now logs them in, straight to the setup page.
        $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($admin);
        $this->get(route('dashboard'))->assertRedirect(route('two-factor.setup'));
    }

    public function test_it_leaves_the_password_and_the_requirement_untouched(): void
    {
        $user = User::factory()->withTwoFactor()->twoFactorRequired()->create();
        $password = $user->password;

        Artisan::call('torsade:reset-two-factor', ['email' => $user->email]);

        $user->refresh();

        $this->assertSame($password, $user->password);
        $this->assertFalse($user->must_change_password);
        $this->assertTrue($user->two_factor_required);
    }
}
