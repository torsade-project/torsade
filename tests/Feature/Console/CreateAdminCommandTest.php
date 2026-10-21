<?php

// torsade/tests/Feature/Console/CreateAdminCommandTest.php

namespace Tests\Feature\Console;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Run the command without interaction and return its exit code and output.
     *
     * @param  array<string, string>  $options
     * @return array{0: int, 1: string}
     */
    private function runCommand(array $options): array
    {
        $exitCode = Artisan::call('torsade:create-admin', [...$options, '--no-interaction' => true]);

        return [$exitCode, Artisan::output()];
    }

    private function passwordIn(string $output): string
    {
        $this->assertMatchesRegularExpression('/Mot de passe temporaire\x{00A0}: (\S+)/u', $output);

        preg_match('/Mot de passe temporaire\x{00A0}: (\S+)/u', $output, $matches);

        return $matches[1];
    }

    public function test_it_creates_an_administrator_and_shows_a_password_of_at_least_20_characters(): void
    {
        [$exitCode, $output] = $this->runCommand(['--name' => 'Alix Admin', '--email' => 'Alix@Example.com']);

        $this->assertSame(0, $exitCode);

        $admin = User::query()->where('email', 'alix@example.com')->firstOrFail();

        $this->assertSame('Alix Admin', $admin->name);
        $this->assertSame(Role::Admin, $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertTrue($admin->must_change_password);

        $password = $this->passwordIn($output);

        $this->assertGreaterThanOrEqual(20, strlen($password));
        $this->assertTrue(Hash::check($password, $admin->password));
        $this->assertSame(1, substr_count($output, $password), 'The password is shown exactly once.');
        $this->assertStringContainsString('ne sera plus affiché', $output);
    }

    public function test_a_second_execution_is_refused_with_a_non_zero_exit_code(): void
    {
        [$first] = $this->runCommand(['--name' => 'Alix Admin', '--email' => 'alix@example.com']);
        [$second, $output] = $this->runCommand(['--name' => 'Second Admin', '--email' => 'second@example.com']);

        $this->assertSame(0, $first);
        $this->assertNotSame(0, $second);
        $this->assertStringContainsString('Un administrateur actif existe déjà', $output);
        $this->assertStringNotContainsString('Mot de passe temporaire', $output);

        $this->assertSame(1, User::count());
        $this->assertDatabaseMissing('users', ['email' => 'second@example.com']);
    }

    public function test_two_executions_never_give_the_same_password(): void
    {
        [, $first] = $this->runCommand(['--name' => 'Alix Admin', '--email' => 'alix@example.com']);

        User::query()->delete();

        [, $second] = $this->runCommand(['--name' => 'Alix Admin', '--email' => 'alix@example.com']);

        $this->assertNotSame($this->passwordIn($first), $this->passwordIn($second));
    }

    public function test_it_asks_for_the_name_and_email_when_they_are_not_given(): void
    {
        $this->artisan('torsade:create-admin')
            ->expectsQuestion('Nom complet', 'Alix Admin')
            ->expectsQuestion('Adresse e-mail', 'alix@example.com')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'alix@example.com', 'role' => 'admin']);
    }

    public function test_it_fails_without_interaction_when_an_option_is_missing(): void
    {
        [$exitCode, $output] = $this->runCommand(['--name' => 'Alix Admin']);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('adresse e-mail est obligatoire', $output);
        $this->assertSame(0, User::count());
    }

    public function test_it_refuses_an_invalid_or_already_used_email(): void
    {
        User::factory()->reader()->create(['email' => 'taken@example.com']);

        [$invalid] = $this->runCommand(['--name' => 'Alix Admin', '--email' => 'not-an-email']);
        [$taken, $output] = $this->runCommand(['--name' => 'Alix Admin', '--email' => 'taken@example.com']);

        $this->assertNotSame(0, $invalid);
        $this->assertNotSame(0, $taken);
        $this->assertStringContainsString('déjà utilisée', $output);
        $this->assertSame(1, User::count());
    }

    public function test_accounts_that_are_not_active_administrators_do_not_block_it(): void
    {
        User::factory()->admin()->inactive()->create();
        User::factory()->editor()->create();

        [$exitCode] = $this->runCommand(['--name' => 'Alix Admin', '--email' => 'alix@example.com']);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseHas('users', ['email' => 'alix@example.com', 'role' => 'admin', 'is_active' => true]);
    }
}
