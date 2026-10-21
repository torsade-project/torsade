<?php

// torsade/tests/Feature/E2eSeederTest.php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\IpAddress;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\E2eSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * The data of the browser tests is only ever created in the testing
 * environment, with a password that comes from outside the code.
 */
class E2eSeederTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD_VARIABLE = 'E2E_PASSWORD';

    protected function tearDown(): void
    {
        putenv(self::PASSWORD_VARIABLE);

        parent::tearDown();
    }

    public function test_it_creates_an_account_per_role_one_with_a_temporary_password_and_the_demonstration_network(): void
    {
        // Drawn for this test: no password is written anywhere.
        $password = bin2hex(random_bytes(12));
        putenv(self::PASSWORD_VARIABLE.'='.$password);

        $this->seed(E2eSeeder::class);

        $this->assertSame(4, User::query()->count());

        foreach ([Role::Admin, Role::Editor, Role::Reader] as $role) {
            $user = User::query()->where('role', $role)->where('must_change_password', false)->sole();

            $this->assertTrue($user->is_active);
            $this->assertTrue(Hash::check($password, $user->password));
            $this->assertStringEndsWith('@example.test', $user->email);
        }

        $newcomer = User::query()->where('must_change_password', true)->sole();

        $this->assertTrue(Hash::check($password, $newcomer->password));

        $this->assertSame(3, Site::query()->count());
        $this->assertSame(107, IpAddress::query()->count());
    }

    public function test_an_empty_installation_has_the_accounts_alone(): void
    {
        putenv(self::PASSWORD_VARIABLE.'='.bin2hex(random_bytes(12)));
        putenv('E2E_DEMONSTRATION=no');

        try {
            $this->seed(E2eSeeder::class);
        } finally {
            putenv('E2E_DEMONSTRATION');
        }

        $this->assertSame(4, User::query()->count());
        $this->assertSame(0, Site::query()->count());
        $this->assertSame(0, IpAddress::query()->count());
    }

    public function test_it_refuses_to_run_outside_the_testing_environment(): void
    {
        putenv(self::PASSWORD_VARIABLE.'='.bin2hex(random_bytes(12)));

        foreach (['production', 'local', 'staging'] as $environment) {
            $this->app->detectEnvironment(fn (): string => $environment);

            try {
                // Called without the command, which asks for a confirmation
                // in production before it reaches the seeder.
                $this->app->make(E2eSeeder::class)->setContainer($this->app)->__invoke();
                $this->fail("The seeder ran in the {$environment} environment.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('testing environment', $exception->getMessage());
            }
        }

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Site::query()->count());
    }

    public function test_it_refuses_to_run_without_a_password_of_twelve_characters(): void
    {
        foreach ([null, '', 'too-short'] as $password) {
            putenv($password === null ? self::PASSWORD_VARIABLE : self::PASSWORD_VARIABLE.'='.$password);

            try {
                $this->seed(E2eSeeder::class);
                $this->fail('The seeder ran without a proper password.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString(self::PASSWORD_VARIABLE, $exception->getMessage());
            }
        }

        $this->assertSame(0, User::query()->count());
    }

    public function test_it_holds_no_password(): void
    {
        $source = (string) file_get_contents(database_path('seeders/E2eSeeder.php'));

        // The only way in is the environment variable.
        $this->assertSame(1, preg_match_all('/[\'"]password[\'"]\s*=>\s*(\S+)/', $source, $matches));
        $this->assertSame('$password,', $matches[1][0]);
        $this->assertStringContainsString("getenv('E2E_PASSWORD')", $source);
    }

    public function test_the_default_seeder_does_not_call_it(): void
    {
        putenv(self::PASSWORD_VARIABLE.'='.bin2hex(random_bytes(12)));

        $this->seed();

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Site::query()->count());
    }
}
