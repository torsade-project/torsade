<?php

// torsade/database/seeders/E2eSeeder.php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use App\Services\Network\DemoData;
use App\Support\Actor;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Data for the browser tests: one account per role, one account that still
 * has a temporary password, and the demonstration network, unless
 * E2E_DEMONSTRATION is "no": the tests of an empty installation ask for the
 * accounts alone.
 *
 * It only runs in the testing environment, and it holds no password: the
 * test runner draws one at random for each run and passes it through the
 * E2E_PASSWORD environment variable, so that no credential ever exists in
 * the code. The default seeder never calls it.
 */
class E2eSeeder extends Seeder
{
    /**
     * The accounts the browser tests log in with.
     *
     * @var list<array{name: string, email: string, role: Role, temporary: bool}>
     */
    public const array ACCOUNTS = [
        ['name' => 'Admin de test', 'email' => 'admin@example.test', 'role' => Role::Admin, 'temporary' => false],
        ['name' => 'Éditeur de test', 'email' => 'editor@example.test', 'role' => Role::Editor, 'temporary' => false],
        ['name' => 'Lecteur de test', 'email' => 'reader@example.test', 'role' => Role::Reader, 'temporary' => false],
        ['name' => 'Arrivant de test', 'email' => 'newcomer@example.test', 'role' => Role::Reader, 'temporary' => true],
    ];

    public function run(DemoData $demo): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('The browser test data can only be created in the testing environment.');
        }

        $password = (string) getenv('E2E_PASSWORD');

        if (strlen($password) < 12) {
            throw new RuntimeException('E2E_PASSWORD must hold the password of the test accounts, 12 characters at least.');
        }

        foreach (self::ACCOUNTS as $account) {
            $user = new User([
                'name' => $account['name'],
                'email' => $account['email'],
                'password' => $password,
            ]);

            $user->role = $account['role'];
            $user->is_active = true;
            $user->must_change_password = $account['temporary'];
            $user->save();
        }

        if (getenv('E2E_DEMONSTRATION') !== 'no') {
            $demo->create(Actor::console());
        }
    }
}
