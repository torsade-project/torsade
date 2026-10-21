<?php

// torsade/tests/Feature/Admin/UserManagementTest.php

namespace Tests\Feature\Admin;

use App\Enums\ActivityEvent;
use App\Enums\Role;
use App\Models\Activity;
use App\Models\User;
use App\Services\UserAccounts;
use App\Support\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Alix Admin']);
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

    // --- List ---------------------------------------------------------------

    public function test_the_list_shows_name_email_role_status_and_last_login(): void
    {
        $user = User::factory()->editor()->inactive()->create([
            'name' => 'Zoé Dubois',
            'email' => 'zoe@example.com',
            'last_login_at' => '2026-03-01 08:30:00',
        ]);

        $this->actingAs($this->admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('users/Index')
                ->has('users.data', 2)
                ->where('users.data.0.name', 'Alix Admin')
                ->where('users.data.0.is_self', true)
                ->where('users.data.1.id', $user->id)
                ->where('users.data.1.name', 'Zoé Dubois')
                ->where('users.data.1.email', 'zoe@example.com')
                ->where('users.data.1.role', 'editor')
                ->where('users.data.1.role_label', 'Éditeur')
                ->where('users.data.1.is_active', false)
                ->where('users.data.1.two_factor', 'disabled')
                ->where('users.data.1.last_login_at', '2026-03-01T08:30:00+00:00')
                ->where('users.data.1.is_self', false)
                ->missing('users.data.1.password')
                ->missing('users.data.1.remember_token'),
            );
    }

    // --- Creation -----------------------------------------------------------

    public function test_an_administrator_creates_a_user_with_a_temporary_password(): void
    {
        $response = $this->actingAs($this->admin)->postJson(route('users.store'), [
            'name' => 'Camille Martin',
            'email' => 'Camille@Example.com',
            'role' => 'editor',
        ]);

        $response->assertCreated();

        $user = User::query()->where('email', 'camille@example.com')->firstOrFail();
        $password = $response->json('temporary_password');

        $this->assertSame('Camille Martin', $user->name);
        $this->assertSame(Role::Editor, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->must_change_password);

        $this->assertIsString($password);
        $this->assertGreaterThanOrEqual(20, strlen($password));
        $this->assertTrue(Hash::check($password, $user->password));
    }

    public function test_the_temporary_password_is_only_in_the_response_and_is_not_cached(): void
    {
        $response = $this->actingAs($this->admin)->postJson(route('users.store'), [
            'name' => 'Camille Martin',
            'email' => 'camille@example.com',
            'role' => 'reader',
        ]);

        $password = $response->json('temporary_password');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString($password, json_encode(session()->all(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($password, json_encode(DB::table('users')->get(), JSON_THROW_ON_ERROR));

        // It is shown once: nothing can return it again.
        $this->actingAs($this->admin)
            ->get(route('users.index'))
            ->assertDontSee($password);
    }

    public function test_the_administrator_cannot_choose_the_password_or_the_flags(): void
    {
        $response = $this->actingAs($this->admin)->postJson(route('users.store'), [
            'name' => 'Camille Martin',
            'email' => 'camille@example.com',
            'role' => 'reader',
            'password' => 'chosen-by-the-admin',
            'is_active' => false,
            'must_change_password' => false,
        ]);

        $response->assertCreated();

        $user = User::query()->where('email', 'camille@example.com')->firstOrFail();

        $this->assertFalse(Hash::check('chosen-by-the-admin', $user->password));
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->must_change_password);
    }

    public function test_creation_is_validated_on_the_server(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($this->admin)
            ->postJson(route('users.store'), ['name' => '', 'email' => 'not-an-email', 'role' => 'superuser'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'role']);

        $this->actingAs($this->admin)
            ->postJson(route('users.store'), ['name' => 'Camille', 'email' => 'TAKEN@example.com', 'role' => 'reader'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'déjà utilisée']);

        $this->assertSame(2, User::count());
    }

    // --- Update -------------------------------------------------------------

    public function test_the_edit_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('users.edit', $user))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('users/Edit')
                ->where('user.id', $user->id)
                ->where('user.is_self', false)
                ->has('roles', 3),
            );
    }

    public function test_an_administrator_updates_name_email_and_role(): void
    {
        $user = User::factory()->reader()->create();

        $this->actingAs($this->admin)
            ->put(route('users.update', $user), [
                'name' => 'Nouveau Nom',
                'email' => 'Nouveau@Example.com',
                'role' => 'editor',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('users.index'));

        $user->refresh();

        $this->assertSame('Nouveau Nom', $user->name);
        $this->assertSame('nouveau@example.com', $user->email);
        $this->assertSame(Role::Editor, $user->role);
    }

    public function test_update_is_validated_on_the_server(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($this->admin)
            ->from(route('users.edit', $user))
            ->put(route('users.update', $user), ['name' => '', 'email' => 'taken@example.com', 'role' => 'owner'])
            ->assertSessionHasErrors(['name', 'email', 'role'])
            ->assertRedirect(route('users.edit', $user));

        // Keeping one's own address is not a duplicate.
        $this->actingAs($this->admin)
            ->put(route('users.update', $other), ['name' => 'Autre', 'email' => 'taken@example.com', 'role' => 'reader'])
            ->assertSessionHasNoErrors();
    }

    public function test_a_role_change_deletes_the_sessions_of_the_user(): void
    {
        $user = User::factory()->reader()->create();
        $previousToken = $user->getRememberToken();

        $this->openSessionFor($user, 'session-of-the-user');
        $this->openSessionFor($this->admin, 'session-of-the-admin');

        $this->actingAs($this->admin)->put(route('users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'role' => 'editor',
        ]);

        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'session-of-the-admin']);
        $this->assertNotSame($previousToken, $user->refresh()->getRememberToken());
    }

    public function test_an_update_without_role_change_keeps_the_sessions(): void
    {
        $user = User::factory()->reader()->create();

        $this->openSessionFor($user, 'session-of-the-user');

        $this->actingAs($this->admin)->put(route('users.update', $user), [
            'name' => 'Autre Nom',
            'email' => $user->email,
            'role' => 'reader',
        ]);

        $this->assertDatabaseHas('sessions', ['id' => 'session-of-the-user']);
    }

    // --- Rules on administrators -------------------------------------------

    public function test_an_administrator_cannot_change_their_own_role(): void
    {
        User::factory()->admin()->create();

        $this->actingAs($this->admin)
            ->from(route('users.edit', $this->admin))
            ->put(route('users.update', $this->admin), [
                'name' => $this->admin->name,
                'email' => $this->admin->email,
                'role' => 'reader',
            ])
            ->assertSessionHasErrors('role');

        $this->assertSame(Role::Admin, $this->admin->refresh()->role);
    }

    public function test_an_administrator_can_still_update_their_own_name_and_email(): void
    {
        $this->actingAs($this->admin)
            ->put(route('users.update', $this->admin), [
                'name' => 'Alix Renommé',
                'email' => 'alix@example.com',
                'role' => 'admin',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Alix Renommé', $this->admin->refresh()->name);
        $this->assertSame('alix@example.com', $this->admin->email);
    }

    public function test_an_administrator_cannot_deactivate_themselves(): void
    {
        User::factory()->admin()->create();

        $this->actingAs($this->admin)
            ->from(route('users.index'))
            ->post(route('users.deactivate', $this->admin))
            ->assertSessionHasErrors('user');

        $this->assertTrue($this->admin->refresh()->is_active);
    }

    public function test_the_last_active_administrator_cannot_be_demoted(): void
    {
        // The only other administrator is deactivated: it does not count.
        User::factory()->admin()->inactive()->create();

        try {
            app(UserAccounts::class)->update(
                $this->admin,
                $this->admin->name,
                $this->admin->email,
                Role::Editor,
                Actor::console(),
            );

            $this->fail('The last active administrator was demoted.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Le dernier administrateur actif ne peut pas changer de rôle.',
                $exception->validator->errors()->first('role'),
            );
        }

        $this->assertSame(Role::Admin, $this->admin->refresh()->role);
    }

    public function test_the_last_active_administrator_cannot_be_deactivated(): void
    {
        User::factory()->admin()->inactive()->create();

        try {
            app(UserAccounts::class)->deactivate($this->admin, Actor::console());

            $this->fail('The last active administrator was deactivated.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Le dernier administrateur actif ne peut pas être désactivé.',
                $exception->validator->errors()->first('user'),
            );
        }

        $this->assertTrue($this->admin->refresh()->is_active);
    }

    public function test_the_only_administrator_cannot_remove_their_own_access_through_the_interface(): void
    {
        $this->actingAs($this->admin)
            ->put(route('users.update', $this->admin), [
                'name' => $this->admin->name,
                'email' => $this->admin->email,
                'role' => 'reader',
            ])
            ->assertSessionHasErrors('role');

        $this->actingAs($this->admin)
            ->post(route('users.deactivate', $this->admin))
            ->assertSessionHasErrors('user');

        $this->admin->refresh();

        $this->assertSame(Role::Admin, $this->admin->role);
        $this->assertTrue($this->admin->is_active);
    }

    public function test_an_administrator_can_demote_or_deactivate_another_administrator(): void
    {
        $second = User::factory()->admin()->create();
        $third = User::factory()->admin()->create();

        $this->actingAs($this->admin)
            ->put(route('users.update', $second), [
                'name' => $second->name,
                'email' => $second->email,
                'role' => 'reader',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('users.deactivate', $third))
            ->assertSessionHasNoErrors();

        $this->assertSame(Role::Reader, $second->refresh()->role);
        $this->assertFalse($third->refresh()->is_active);
        $this->assertTrue(app(UserAccounts::class)->hasActiveAdmin());
    }

    // --- Password reset -----------------------------------------------------

    public function test_an_administrator_resets_a_password_with_a_temporary_one(): void
    {
        $user = User::factory()->create();
        $previousToken = $user->getRememberToken();

        $this->openSessionFor($user, 'session-of-the-user');

        $response = $this->actingAs($this->admin)->postJson(route('users.reset-password', $user));

        $response->assertOk();

        $password = $response->json('temporary_password');
        $user->refresh();

        $this->assertGreaterThanOrEqual(20, strlen($password));
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertNotSame($previousToken, $user->getRememberToken());
    }

    public function test_an_administrator_cannot_reset_their_own_password(): void
    {
        $hash = $this->admin->password;
        $this->openSessionFor($this->admin, 'session-of-the-administrator');

        $this->actingAs($this->admin)
            ->postJson(route('users.reset-password', $this->admin))
            ->assertForbidden();

        // Nothing changed: the password, the sessions, the log.
        $this->admin->refresh();
        $this->assertSame($hash, $this->admin->password);
        $this->assertFalse($this->admin->must_change_password);
        $this->assertDatabaseHas('sessions', ['user_id' => $this->admin->id]);
        $this->assertSame(0, Activity::query()->where('event', ActivityEvent::PasswordReset->value)->count());
    }

    public function test_the_list_does_not_offer_one_to_reset_ones_own_password(): void
    {
        $other = User::factory()->create();

        $users = collect($this->actingAs($this->admin)->get(route('users.index'))->assertOk()->inertiaProps('users.data'))->keyBy('id');

        // The page hides the button on the line marked as one's own.
        $this->assertTrue($users[$this->admin->id]['is_self']);
        $this->assertFalse($users[$other->id]['is_self']);
    }

    public function test_two_resets_give_two_different_passwords(): void
    {
        $user = User::factory()->create();

        $first = $this->actingAs($this->admin)->postJson(route('users.reset-password', $user))->json('temporary_password');
        $second = $this->actingAs($this->admin)->postJson(route('users.reset-password', $user))->json('temporary_password');

        $this->assertNotSame($first, $second);
    }

    // --- No deletion --------------------------------------------------------

    public function test_there_is_no_way_to_delete_an_account(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(Route::has('users.destroy'));

        $this->actingAs($this->admin)
            ->delete("/users/{$user->id}")
            ->assertStatus(405);

        $this->assertNotNull($user->fresh());
    }
}
