<?php

// torsade/tests/Feature/AuthorizationTest.php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The three roles and the gates built on them. Hiding a menu entry
 * is not an authorisation: every refusal is checked on the server.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every route reserved to administrators.
     *
     * @return array<string, array{string, string}>
     */
    public static function adminRoutes(): array
    {
        return [
            'user list' => ['get', 'users.index'],
            'user creation form' => ['get', 'users.create'],
            'user creation' => ['post', 'users.store'],
            'user edit form' => ['get', 'users.edit'],
            'user update' => ['put', 'users.update'],
            'user deactivation' => ['post', 'users.deactivate'],
            'user reactivation' => ['post', 'users.activate'],
            'password reset' => ['post', 'users.reset-password'],
            'activity log' => ['get', 'activity-log.index'],
        ];
    }

    private function urlFor(string $routeName, User $target): string
    {
        return in_array($routeName, ['users.index', 'users.create', 'users.store', 'activity-log.index'], true)
            ? route($routeName)
            : route($routeName, $target);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return ['name' => 'Camille Martin', 'email' => 'camille@example.com', 'role' => 'admin'];
    }

    #[DataProvider('adminRoutes')]
    public function test_a_reader_receives_a_403_on_admin_routes(string $method, string $routeName): void
    {
        $target = User::factory()->create(['name' => 'Target']);

        $this->actingAs(User::factory()->reader()->create())
            ->call($method, $this->urlFor($routeName, $target), $this->payload())
            ->assertForbidden();

        $this->assertTargetUntouched($target);
    }

    #[DataProvider('adminRoutes')]
    public function test_an_editor_receives_a_403_on_admin_routes(string $method, string $routeName): void
    {
        $target = User::factory()->create(['name' => 'Target']);

        $this->actingAs(User::factory()->editor()->create())
            ->call($method, $this->urlFor($routeName, $target), $this->payload())
            ->assertForbidden();

        $this->assertTargetUntouched($target);
    }

    #[DataProvider('adminRoutes')]
    public function test_a_guest_is_sent_to_the_login_page(string $method, string $routeName): void
    {
        $target = User::factory()->create(['name' => 'Target']);

        $this->call($method, $this->urlFor($routeName, $target), $this->payload())
            ->assertRedirect(route('login'));

        $this->assertTargetUntouched($target);
    }

    private function assertTargetUntouched(User $target): void
    {
        $fresh = $target->fresh();

        $this->assertNotNull($fresh);
        $this->assertSame('Target', $fresh->name);
        $this->assertSame(Role::Reader, $fresh->role);
        $this->assertTrue($fresh->is_active);
        $this->assertFalse($fresh->must_change_password);
        $this->assertDatabaseMissing('users', ['email' => 'camille@example.com']);
    }

    public function test_an_administrator_can_open_the_users_and_activity_log_pages(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('users.index'))->assertOk();
        $this->actingAs($admin)->get(route('users.create'))->assertOk();
        $this->actingAs($admin)->get(route('users.edit', $admin))->assertOk();
        $this->actingAs($admin)->get(route('activity-log.index'))->assertOk();
    }

    public function test_every_role_can_open_the_dashboard(): void
    {
        foreach (Role::cases() as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('dashboard'))
                ->assertOk();
        }
    }

    public function test_the_manage_users_gate_is_for_administrators(): void
    {
        $this->assertFalse(Gate::forUser(User::factory()->reader()->make())->allows('manage-users'));
        $this->assertFalse(Gate::forUser(User::factory()->editor()->make())->allows('manage-users'));
        $this->assertTrue(Gate::forUser(User::factory()->admin()->make())->allows('manage-users'));
    }

    public function test_the_view_activity_log_gate_is_for_administrators(): void
    {
        $this->assertFalse(Gate::forUser(User::factory()->reader()->make())->allows('view-activity-log'));
        $this->assertFalse(Gate::forUser(User::factory()->editor()->make())->allows('view-activity-log'));
        $this->assertTrue(Gate::forUser(User::factory()->admin()->make())->allows('view-activity-log'));
    }

    public function test_the_edit_network_gate_is_for_editors_and_administrators(): void
    {
        $this->assertFalse(Gate::forUser(User::factory()->reader()->make())->allows('edit-network'));
        $this->assertTrue(Gate::forUser(User::factory()->editor()->make())->allows('edit-network'));
        $this->assertTrue(Gate::forUser(User::factory()->admin()->make())->allows('edit-network'));
    }

    public function test_the_view_network_gate_is_for_the_three_roles(): void
    {
        foreach (Role::cases() as $role) {
            $this->assertTrue(Gate::forUser(User::factory()->make(['role' => $role]))->allows('view-network'));
        }
    }

    public function test_the_two_factor_gate_is_for_administrators_on_other_accounts(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->create();

        $this->assertTrue(Gate::forUser($admin)->allows('manage-user-two-factor', $other));
        $this->assertFalse(Gate::forUser($admin)->allows('manage-user-two-factor', $admin));
        $this->assertFalse(Gate::forUser(User::factory()->editor()->create())->allows('manage-user-two-factor', $other));
        $this->assertFalse(Gate::forUser(User::factory()->reader()->create())->allows('manage-user-two-factor', $other));
    }

    public function test_the_password_reset_gate_is_for_administrators_on_other_accounts(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->create();

        $this->assertTrue(Gate::forUser($admin)->allows('reset-user-password', $other));
        $this->assertFalse(Gate::forUser($admin)->allows('reset-user-password', $admin));
        $this->assertFalse(Gate::forUser(User::factory()->editor()->create())->allows('reset-user-password', $other));
        $this->assertFalse(Gate::forUser(User::factory()->admin()->inactive()->create())->allows('reset-user-password', $other));
    }

    public function test_a_deactivated_account_is_granted_nothing(): void
    {
        $admin = User::factory()->admin()->inactive()->create();
        $other = User::factory()->create();

        foreach (['manage-users', 'view-activity-log', 'edit-network', 'view-network'] as $ability) {
            $this->assertFalse(Gate::forUser($admin)->allows($ability));
        }

        $this->assertFalse(Gate::forUser($admin)->allows('manage-user-two-factor', $other));
    }

    public function test_the_dashboard_is_behind_the_view_network_gate(): void
    {
        $this->assertContains('can:view-network', Route::getRoutes()->getByName('dashboard')?->gatherMiddleware() ?? []);

        // Refused when the gate says no, whatever the role.
        Gate::define('view-network', fn (User $user): bool => false);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertForbidden();
    }

    public function test_a_new_account_is_a_reader_by_default(): void
    {
        $user = User::create([
            'name' => 'Camille Martin',
            'email' => 'camille@example.com',
            'password' => 'a-long-enough-password',
        ]);

        $this->assertSame(Role::Reader, $user->refresh()->role);
        $this->assertTrue($user->is_active);
        $this->assertFalse($user->must_change_password);
        $this->assertNull($user->last_login_at);
    }

    public function test_role_status_and_forced_change_are_not_mass_assignable(): void
    {
        $user = User::create([
            'name' => 'Camille Martin',
            'email' => 'camille@example.com',
            'password' => 'a-long-enough-password',
            'role' => 'admin',
            'is_active' => false,
            'must_change_password' => true,
        ]);

        $this->assertSame(Role::Reader, $user->refresh()->role);
        $this->assertTrue($user->is_active);
        $this->assertFalse($user->must_change_password);
    }

    public function test_the_navigation_permissions_follow_the_role(): void
    {
        // For each role: administration entries, then network edition buttons.
        $expected = [
            Role::Reader->value => [false, false],
            Role::Editor->value => [false, true],
            Role::Admin->value => [true, true],
        ];

        foreach ($expected as $role => [$administers, $edits]) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('dashboard'))
                ->assertInertia(fn (Assert $page) => $page
                    ->where('auth.user.role', $role)
                    ->where('auth.can.manageUsers', $administers)
                    ->where('auth.can.viewActivityLog', $administers)
                    ->where('auth.can.editNetwork', $edits),
                );
        }
    }

    public function test_only_an_explicit_subset_of_the_user_is_sent_to_the_browser(): void
    {
        $this->actingAs(User::factory()->withTwoFactor()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('auth.user', 4)
                ->hasAll(['auth.user.id', 'auth.user.name', 'auth.user.email', 'auth.user.role']),
            );
    }
}
