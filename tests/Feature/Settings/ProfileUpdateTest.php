<?php

// torsade/tests/Feature/Settings/ProfileUpdateTest.php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_requires_authentication(): void
    {
        $this->get(route('profile.edit'))->assertRedirect(route('login'));
    }

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    }

    public function test_name_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Camille Martin',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertSame('Camille Martin', $user->refresh()->name);
    }

    public function test_name_is_required(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), ['name' => ''])
            ->assertSessionHasErrors('name');
    }

    public function test_a_user_cannot_change_their_own_email_address(): void
    {
        $user = User::factory()->create(['email' => 'camille@example.com']);

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Camille Martin',
                'email' => 'someone-else@example.com',
            ]);

        $response->assertSessionHasNoErrors();

        $this->assertSame('camille@example.com', $user->refresh()->email);
    }

    public function test_a_user_cannot_delete_their_own_account(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(Route::has('profile.destroy'));

        $this
            ->actingAs($user)
            ->delete('/settings/profile', ['password' => 'password'])
            ->assertStatus(405);

        $this->assertNotNull($user->fresh());
    }
}
