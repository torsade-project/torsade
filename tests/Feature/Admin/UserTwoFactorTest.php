<?php

// torsade/tests/Feature/Admin/UserTwoFactorTest.php

namespace Tests\Feature\Admin;

use App\Enums\ActivityEvent;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * An administrator can require or reset the two-factor authentication of
 * another account, never enable it: whoever enables it holds the secret.
 */
class UserTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Alix Admin']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function actions(): array
    {
        return [
            'require' => ['users.two-factor.enforce'],
            'stop requiring' => ['users.two-factor.release'],
            'reset' => ['users.two-factor.reset'],
        ];
    }

    private function lastEntry(ActivityEvent $event): Activity
    {
        return Activity::query()->where('event', $event->value)->orderByDesc('id')->firstOrFail();
    }

    private function countEntries(ActivityEvent $event): int
    {
        return Activity::query()->where('event', $event->value)->count();
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

    // --- Requiring ----------------------------------------------------------

    public function test_an_administrator_can_require_two_factor_authentication(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('users.two-factor.enforce', $user))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('users.edit', $user));

        $this->assertTrue($user->refresh()->two_factor_required);

        $entry = $this->lastEntry(ActivityEvent::TwoFactorRequired);

        $this->assertTrue($this->admin->is($entry->causer));
        $this->assertTrue($user->is($entry->subject));
        $this->assertSame(['two_factor_required' => false], $entry->attribute_changes?->get('old'));
        $this->assertSame(['two_factor_required' => true], $entry->attribute_changes?->get('attributes'));
    }

    public function test_an_administrator_can_stop_requiring_it(): void
    {
        $user = User::factory()->twoFactorRequired()->create();

        $this->actingAs($this->admin)
            ->post(route('users.two-factor.release', $user))
            ->assertRedirect(route('users.edit', $user));

        $this->assertFalse($user->refresh()->two_factor_required);

        $entry = $this->lastEntry(ActivityEvent::TwoFactorNoLongerRequired);

        $this->assertTrue($this->admin->is($entry->causer));
        $this->assertTrue($user->is($entry->subject));
        $this->assertSame(['two_factor_required' => true], $entry->attribute_changes?->get('old'));
        $this->assertSame(['two_factor_required' => false], $entry->attribute_changes?->get('attributes'));
    }

    public function test_repeating_a_decision_changes_nothing_and_is_not_logged_twice(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin)->post(route('users.two-factor.enforce', $user));
        $this->actingAs($this->admin)->post(route('users.two-factor.enforce', $user));

        $this->assertSame(1, $this->countEntries(ActivityEvent::TwoFactorRequired));

        $this->actingAs($this->admin)->post(route('users.two-factor.release', $user));
        $this->actingAs($this->admin)->post(route('users.two-factor.release', $user));

        $this->assertSame(1, $this->countEntries(ActivityEvent::TwoFactorNoLongerRequired));
    }

    public function test_requiring_it_does_not_configure_it_for_the_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin)->post(route('users.two-factor.enforce', $user));

        $user->refresh();

        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertSame('required', $user->twoFactorState());
    }

    // --- Resetting ----------------------------------------------------------

    public function test_an_administrator_can_reset_two_factor_authentication(): void
    {
        $user = User::factory()->withTwoFactor()->twoFactorRequired()->create();
        $previousToken = $user->getRememberToken();

        $this->openSessionFor($user, 'session-of-the-user-1');
        $this->openSessionFor($user, 'session-of-the-user-2');
        $this->openSessionFor($this->admin, 'session-of-the-admin');

        $this->actingAs($this->admin)
            ->post(route('users.two-factor.reset', $user))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('users.edit', $user));

        $user->refresh();

        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());

        // The requirement stays: the user sets it up again at the next login.
        $this->assertTrue($user->two_factor_required);
        $this->assertTrue($user->mustSetUpTwoFactor());

        // Every session of the account is closed, and only of that account.
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'session-of-the-admin']);
        $this->assertNotSame($previousToken, $user->getRememberToken());

        $entry = $this->lastEntry(ActivityEvent::TwoFactorReset);

        $this->assertTrue($this->admin->is($entry->causer));
        $this->assertTrue($user->is($entry->subject));
        $this->assertSame('web', $entry->getProperty('source'));
    }

    public function test_no_two_factor_secret_ever_reaches_the_activity_log(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $secret = $user->two_factor_secret;
        $codes = $user->two_factor_recovery_codes;

        $this->actingAs($this->admin)->post(route('users.two-factor.enforce', $user));
        $this->actingAs($this->admin)->post(route('users.two-factor.reset', $user));
        $this->actingAs($this->admin)->post(route('users.two-factor.release', $user));

        $log = json_encode(DB::table('activity_log')->get(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString((string) $secret, $log);
        $this->assertStringNotContainsString((string) $codes, $log);
        $this->assertDoesNotMatchRegularExpression('/secret|recovery/i', $log);
    }

    // --- Refusals -----------------------------------------------------------

    #[DataProvider('actions')]
    public function test_the_actions_are_refused_on_ones_own_account(string $routeName): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $secret = $admin->two_factor_secret;

        // Another administrator exists: only the "own account" rule applies.
        $this->actingAs($admin)
            ->post(route($routeName, $admin))
            ->assertForbidden();

        $admin->refresh();

        $this->assertFalse($admin->two_factor_required);
        $this->assertSame($secret, $admin->two_factor_secret);
        $this->assertNotNull($admin->two_factor_confirmed_at);
        $this->assertSame(0, Activity::query()->where('event', 'like', 'user.two_factor%')->count());
    }

    #[DataProvider('actions')]
    public function test_the_actions_are_refused_to_readers(string $routeName): void
    {
        $this->assertRefusedTo(User::factory()->reader()->create(), $routeName);
    }

    #[DataProvider('actions')]
    public function test_the_actions_are_refused_to_editors(string $routeName): void
    {
        $this->assertRefusedTo(User::factory()->editor()->create(), $routeName);
    }

    private function assertRefusedTo(User $actor, string $routeName): void
    {
        $target = User::factory()->withTwoFactor()->create();

        $this->actingAs($actor)
            ->post(route($routeName, $target))
            ->assertForbidden();

        $target->refresh();

        $this->assertFalse($target->two_factor_required);
        $this->assertTrue($target->hasEnabledTwoFactorAuthentication());
    }

    #[DataProvider('actions')]
    public function test_the_actions_send_a_guest_to_the_login_page(string $routeName): void
    {
        $target = User::factory()->withTwoFactor()->create();

        $this->post(route($routeName, $target))->assertRedirect(route('login'));

        $this->assertTrue($target->refresh()->hasEnabledTwoFactorAuthentication());
    }

    // --- Password reset -----------------------------------------------------

    public function test_a_password_reset_by_an_administrator_leaves_two_factor_authentication_intact(): void
    {
        $user = User::factory()->withTwoFactor()->twoFactorRequired()->create();
        $secret = $user->two_factor_secret;
        $codes = $user->two_factor_recovery_codes;

        $this->actingAs($this->admin)
            ->postJson(route('users.reset-password', $user))
            ->assertOk();

        $user->refresh();

        $this->assertTrue($user->must_change_password);
        $this->assertSame($secret, $user->two_factor_secret);
        $this->assertSame($codes, $user->two_factor_recovery_codes);
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertTrue($user->two_factor_required);
        $this->assertTrue($user->hasEnabledTwoFactorAuthentication());
    }

    public function test_a_password_reset_from_the_console_leaves_two_factor_authentication_intact(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $secret = $user->two_factor_secret;

        Artisan::call('torsade:reset-password', ['email' => $user->email]);

        $user->refresh();

        $this->assertSame($secret, $user->two_factor_secret);
        $this->assertTrue($user->hasEnabledTwoFactorAuthentication());
    }

    // --- Display ------------------------------------------------------------

    public function test_the_list_shows_the_two_factor_state_of_each_account(): void
    {
        User::factory()->withTwoFactor()->create(['name' => 'B Enabled']);
        User::factory()->twoFactorRequired()->create(['name' => 'C Required']);
        User::factory()->create(['name' => 'D Disabled']);
        User::factory()->withTwoFactor()->twoFactorRequired()->create(['name' => 'E Required and enabled']);

        $this->actingAs($this->admin)
            ->get(route('users.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.data.0.name', 'Alix Admin')
                ->where('users.data.0.two_factor', 'disabled')
                ->where('users.data.1.two_factor', 'enabled')
                ->where('users.data.2.two_factor', 'required')
                ->where('users.data.3.two_factor', 'disabled')
                ->where('users.data.4.two_factor', 'enabled')
                ->missing('users.data.1.two_factor_secret')
                ->missing('users.data.1.two_factor_recovery_codes'),
            );
    }

    public function test_the_edit_page_receives_the_two_factor_state(): void
    {
        $user = User::factory()->twoFactorRequired()->create();

        $this->actingAs($this->admin)
            ->get(route('users.edit', $user))
            ->assertInertia(fn (Assert $page) => $page
                ->where('user.two_factor', 'required')
                ->where('user.two_factor_required', true)
                ->where('user.is_self', false),
            );
    }

    public function test_the_requirement_is_not_mass_assignable(): void
    {
        $user = User::factory()->create();

        $user->fill(['two_factor_required' => true])->save();

        $this->assertFalse($user->refresh()->two_factor_required);

        // Nor through the update form of the administration.
        $this->actingAs($this->admin)->put(route('users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'role' => 'reader',
            'two_factor_required' => true,
        ]);

        $this->assertFalse($user->refresh()->two_factor_required);
    }
}
