<?php

// torsade/tests/Feature/ActivityLogTest.php

namespace Tests\Feature;

use App\Enums\ActivityEvent;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use LogicException;
use Tests\TestCase;

/**
 * Every change to an account and every authentication event is written to
 * the activity log, without any password or token.
 */
class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private const IP = '203.0.113.7';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withServerVariables(['REMOTE_ADDR' => self::IP]);
    }

    private function lastEntry(ActivityEvent $event): Activity
    {
        return Activity::query()->where('event', $event->value)->orderByDesc('id')->firstOrFail();
    }

    private function countEntries(ActivityEvent $event): int
    {
        return Activity::query()->where('event', $event->value)->count();
    }

    /**
     * The whole table, as stored, to search for values that must not be there.
     */
    private function rawLog(): string
    {
        return json_encode(DB::table('activity_log')->get(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    // --- Accounts -----------------------------------------------------------

    public function test_creating_a_user_is_logged_without_the_password(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson(route('users.store'), [
            'name' => 'Camille Martin',
            'email' => 'camille@example.com',
            'role' => 'editor',
        ]);

        $created = User::query()->where('email', 'camille@example.com')->firstOrFail();
        $entry = $this->lastEntry(ActivityEvent::UserCreated);

        $this->assertTrue($admin->is($entry->causer));
        $this->assertTrue($created->is($entry->subject));
        $this->assertSame(
            ['name' => 'Camille Martin', 'email' => 'camille@example.com', 'role' => 'editor'],
            $entry->attribute_changes?->get('attributes'),
        );
        $this->assertSame('web', $entry->getProperty('source'));
        $this->assertSame(self::IP, $entry->getProperty('ip'));

        $this->assertStringNotContainsString($response->json('temporary_password'), $this->rawLog());
        $this->assertStringNotContainsString($created->password, $this->rawLog());
    }

    public function test_updating_a_user_is_logged_with_values_before_and_after(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['name' => 'Ancien Nom', 'email' => 'ancien@example.com']);

        $this->actingAs($admin)->put(route('users.update', $user), [
            'name' => 'Nouveau Nom',
            'email' => 'nouveau@example.com',
            'role' => 'reader',
        ]);

        $entry = $this->lastEntry(ActivityEvent::UserUpdated);

        $this->assertTrue($admin->is($entry->causer));
        $this->assertTrue($user->is($entry->subject));
        $this->assertSame(['name' => 'Ancien Nom', 'email' => 'ancien@example.com'], $entry->attribute_changes?->get('old'));
        $this->assertSame(['name' => 'Nouveau Nom', 'email' => 'nouveau@example.com'], $entry->attribute_changes?->get('attributes'));

        // The role did not change: no role entry.
        $this->assertSame(0, $this->countEntries(ActivityEvent::UserRoleChanged));
    }

    public function test_an_update_that_changes_nothing_is_not_logged(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)->put(route('users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'role' => 'reader',
        ]);

        $this->assertSame(0, $this->countEntries(ActivityEvent::UserUpdated));
        $this->assertSame(0, $this->countEntries(ActivityEvent::UserRoleChanged));
    }

    public function test_a_user_renaming_themselves_is_logged(): void
    {
        $user = User::factory()->create(['name' => 'Ancien Nom']);

        $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Nouveau Nom']);

        $entry = $this->lastEntry(ActivityEvent::UserUpdated);

        $this->assertTrue($user->is($entry->causer));
        $this->assertTrue($user->is($entry->subject));
        $this->assertSame(['name' => 'Ancien Nom'], $entry->attribute_changes?->get('old'));
        $this->assertSame(['name' => 'Nouveau Nom'], $entry->attribute_changes?->get('attributes'));
    }

    public function test_a_role_change_is_logged(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->reader()->create();

        $this->actingAs($admin)->put(route('users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'role' => 'admin',
        ]);

        $entry = $this->lastEntry(ActivityEvent::UserRoleChanged);

        $this->assertTrue($admin->is($entry->causer));
        $this->assertTrue($user->is($entry->subject));
        $this->assertSame(['role' => 'reader'], $entry->attribute_changes?->get('old'));
        $this->assertSame(['role' => 'admin'], $entry->attribute_changes?->get('attributes'));
    }

    public function test_deactivation_and_reactivation_are_logged(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)->post(route('users.deactivate', $user));

        $deactivated = $this->lastEntry(ActivityEvent::UserDeactivated);

        $this->assertTrue($admin->is($deactivated->causer));
        $this->assertTrue($user->is($deactivated->subject));

        $this->actingAs($admin)->post(route('users.activate', $user));

        $reactivated = $this->lastEntry(ActivityEvent::UserReactivated);

        $this->assertTrue($admin->is($reactivated->causer));
        $this->assertTrue($user->is($reactivated->subject));
    }

    // --- Authentication -----------------------------------------------------

    public function test_a_successful_login_is_logged_with_the_ip_address(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $entry = $this->lastEntry(ActivityEvent::LoginSucceeded);

        $this->assertTrue($user->is($entry->causer));
        $this->assertTrue($user->is($entry->subject));
        $this->assertSame(self::IP, $entry->getProperty('ip'));
        $this->assertNotNull($user->refresh()->last_login_at);
        $this->assertStringNotContainsString('"password"', $this->rawLog());
    }

    public function test_a_failed_login_on_a_known_account_references_the_account(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Typed-Wrong-Passw0rd']);

        $entry = $this->lastEntry(ActivityEvent::LoginFailed);

        $this->assertNull($entry->causer);
        $this->assertTrue($user->is($entry->subject));
        $this->assertSame(self::IP, $entry->getProperty('ip'));
        $this->assertStringNotContainsString('Typed-Wrong-Passw0rd', $this->rawLog());
    }

    public function test_a_failed_login_on_an_unknown_email_does_not_store_what_was_typed(): void
    {
        // A password typed in the e-mail field by mistake.
        $this->post(route('login.store'), ['email' => 'MySecretPassw0rd', 'password' => 'Typed-Wrong-Passw0rd']);

        $entry = $this->lastEntry(ActivityEvent::LoginFailed);

        $this->assertNull($entry->causer);
        $this->assertNull($entry->subject);
        $this->assertSame(self::IP, $entry->getProperty('ip'));

        $log = strtolower($this->rawLog());

        $this->assertStringNotContainsString('mysecretpassw0rd', $log);
        $this->assertStringNotContainsString('typed-wrong-passw0rd', $log);
    }

    public function test_a_refused_login_on_a_deactivated_account_is_logged(): void
    {
        $user = User::factory()->inactive()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $entry = $this->lastEntry(ActivityEvent::LoginFailed);

        $this->assertTrue($user->is($entry->subject));
        $this->assertSame('inactive', $entry->getProperty('reason'));
        $this->assertSame(0, $this->countEntries(ActivityEvent::LoginSucceeded));
    }

    public function test_a_locked_login_attempt_is_logged(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 6) as $attempt) {
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $this->assertSame(6, $this->countEntries(ActivityEvent::LoginFailed));
        $this->assertSame('locked', $this->lastEntry(ActivityEvent::LoginFailed)->getProperty('reason'));
    }

    public function test_a_wrong_two_factor_code_is_logged_as_a_failed_login(): void
    {
        // A real secret, so that the code is verified and not just rejected.
        $user = User::factory()->withTwoFactor()->create([
            'two_factor_secret' => encrypt(app(TwoFactorAuthenticationProvider::class)->generateSecretKey()),
        ]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->post(route('two-factor.login.store'), ['code' => '000000'])->assertRedirect();

        $entry = $this->lastEntry(ActivityEvent::LoginFailed);

        $this->assertTrue($user->is($entry->subject));
        $this->assertSame('two_factor', $entry->getProperty('reason'));
        $this->assertGuest();
    }

    public function test_a_logout_is_logged(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('logout'));

        $entry = $this->lastEntry(ActivityEvent::LoggedOut);

        $this->assertTrue($user->is($entry->causer));
        $this->assertSame(self::IP, $entry->getProperty('ip'));
    }

    public function test_a_password_change_is_logged_without_any_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'My-New-Secret-Passw0rd',
            'password_confirmation' => 'My-New-Secret-Passw0rd',
        ]);

        $entry = $this->lastEntry(ActivityEvent::PasswordChanged);

        $this->assertTrue($user->is($entry->causer));
        $this->assertTrue($user->is($entry->subject));
        $this->assertSame(self::IP, $entry->getProperty('ip'));

        $log = $this->rawLog();

        $this->assertStringNotContainsString('My-New-Secret-Passw0rd', $log);
        $this->assertStringNotContainsString($user->refresh()->password, $log);
    }

    public function test_a_refused_password_change_is_not_logged(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('user-password.update'), [
            'current_password' => 'wrong-password',
            'password' => 'My-New-Secret-Passw0rd',
            'password_confirmation' => 'My-New-Secret-Passw0rd',
        ]);

        $this->assertSame(0, $this->countEntries(ActivityEvent::PasswordChanged));
    }

    public function test_a_password_reset_by_an_administrator_is_logged_without_the_password(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($admin)->postJson(route('users.reset-password', $user));

        $entry = $this->lastEntry(ActivityEvent::PasswordReset);

        $this->assertTrue($admin->is($entry->causer));
        $this->assertTrue($user->is($entry->subject));

        $log = $this->rawLog();

        $this->assertStringNotContainsString($response->json('temporary_password'), $log);
        $this->assertStringNotContainsString($user->refresh()->password, $log);
        $this->assertStringNotContainsString((string) $user->getRememberToken(), $log);
    }

    // --- Console ------------------------------------------------------------

    public function test_the_create_admin_command_is_logged_with_the_console_as_author(): void
    {
        Artisan::call('torsade:create-admin', ['--name' => 'Alix Admin', '--email' => 'alix@example.com']);

        $output = Artisan::output();
        $admin = User::query()->where('email', 'alix@example.com')->firstOrFail();

        $command = $this->lastEntry(ActivityEvent::CommandExecuted);

        $this->assertNull($command->causer);
        $this->assertSame('console', $command->getProperty('source'));
        $this->assertSame('torsade:create-admin', $command->getProperty('command'));
        $this->assertSame('success', $command->getProperty('outcome'));
        $this->assertNull($command->getProperty('ip'));

        $created = $this->lastEntry(ActivityEvent::UserCreated);

        $this->assertNull($created->causer);
        $this->assertSame('console', $created->getProperty('source'));
        $this->assertTrue($admin->is($created->subject));

        preg_match('/[A-Za-z0-9]{20,}/', $output, $matches);

        $this->assertNotEmpty($matches);
        $this->assertStringNotContainsString($matches[0], $this->rawLog());
    }

    public function test_a_refused_create_admin_command_is_logged(): void
    {
        User::factory()->admin()->create();

        Artisan::call('torsade:create-admin', ['--name' => 'Alix Admin', '--email' => 'alix@example.com']);

        $command = $this->lastEntry(ActivityEvent::CommandExecuted);

        $this->assertSame('refused', $command->getProperty('outcome'));
        $this->assertSame(0, $this->countEntries(ActivityEvent::UserCreated));
    }

    public function test_the_reset_password_command_is_logged_with_the_console_as_author(): void
    {
        $user = User::factory()->create();

        Artisan::call('torsade:reset-password', ['email' => $user->email]);

        $command = $this->lastEntry(ActivityEvent::CommandExecuted);

        $this->assertNull($command->causer);
        $this->assertTrue($user->is($command->subject));
        $this->assertSame('console', $command->getProperty('source'));
        $this->assertSame('torsade:reset-password', $command->getProperty('command'));

        $reset = $this->lastEntry(ActivityEvent::PasswordReset);

        $this->assertNull($reset->causer);
        $this->assertTrue($user->is($reset->subject));
        $this->assertSame('console', $reset->getProperty('source'));
    }

    public function test_the_reset_password_command_does_not_store_an_unknown_email(): void
    {
        Artisan::call('torsade:reset-password', ['email' => 'MySecretPassw0rd@example.com']);

        $this->assertSame('unknown_account', $this->lastEntry(ActivityEvent::CommandExecuted)->getProperty('outcome'));
        $this->assertStringNotContainsString('mysecretpassw0rd', strtolower($this->rawLog()));
    }

    // --- Integrity ----------------------------------------------------------

    public function test_no_entry_ever_holds_a_secret(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->withTwoFactor()->create();

        $this->actingAs($admin)->postJson(route('users.store'), ['name' => 'A', 'email' => 'a@example.com', 'role' => 'reader']);
        $this->actingAs($admin)->postJson(route('users.reset-password', $user));
        $this->actingAs($admin)->put(route('users.update', $user), ['name' => 'B', 'email' => 'b@example.com', 'role' => 'editor']);
        $this->actingAs($admin)->post(route('users.deactivate', $user));

        $this->assertGreaterThanOrEqual(5, Activity::count());

        foreach (DB::table('activity_log')->get() as $row) {
            foreach (['attribute_changes', 'properties'] as $column) {
                $this->assertDoesNotMatchRegularExpression(
                    '/password|token|secret|recovery/i',
                    (string) $row->{$column},
                    "Entry {$row->id} holds a secret in {$column}.",
                );
            }
        }
    }

    public function test_entries_cannot_be_modified_or_deleted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('logout'));

        $entry = $this->lastEntry(ActivityEvent::LoggedOut);

        try {
            $entry->description = 'tampered';
            $entry->save();

            $this->fail('An entry was modified.');
        } catch (LogicException) {
            $this->assertSame('auth.logout', $entry->fresh()?->description);
        }

        try {
            $entry->delete();

            $this->fail('An entry was deleted.');
        } catch (LogicException) {
            $this->assertNotNull($entry->fresh());
        }
    }
}
