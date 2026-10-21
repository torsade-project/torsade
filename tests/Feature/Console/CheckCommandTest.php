<?php

// torsade/tests/Feature/Console/CheckCommandTest.php

namespace Tests\Feature\Console;

use App\Services\ProductionChecks;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\View\Compilers\BladeCompiler;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * torsade:check on an installation laid out in a temporary folder, as the
 * installer leaves it: every check passes, then each one fails in turn and
 * says what to correct. The command writes nothing and prints no secret.
 * The rights on the files are only checked on Linux, which the CI runs.
 */
class CheckCommandTest extends TestCase
{
    use RefreshDatabase;

    private const string KEY = 'base64:c2VjcmV0LWtleS1vZi10aGUtdGVzdC0wMTIzNDU2Nzg=';

    private const string DATABASE_PASSWORD = 'mot-de-passe-de-la-base-du-test';

    private string $folder;

    /**
     * The lines SHOW GRANTS gives the checks, and whether they run on Linux.
     *
     * @var list<string>
     */
    private array $grants = [];

    private bool $linux = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'torsade-check-'.bin2hex(random_bytes(4));

        foreach (['bootstrap/cache', 'storage', 'views', 'data', 'logs', 'migrations'] as $folder) {
            File::ensureDirectoryExists($this->path($folder));
        }

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->folder));

        // The configuration of an installation.
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => self::KEY,
            'app.url' => 'https://torsade.example.test',
            'session.cookie' => '__Host-torsade-session',
            'session.secure' => true,
            'session.path' => '/',
            'session.domain' => null,
            'view.compiled' => $this->path('views'),
        ]);

        $connection = config('database.default');
        config(["database.connections.{$connection}.password" => self::DATABASE_PASSWORD]);

        // Its files: .env, then the caches built after it.
        $this->app->useEnvironmentPath($this->folder);
        file_put_contents($this->path('.env'), "APP_ENV=production\n");
        touch($this->path('.env'), time() - 60);

        $this->app->useBootstrapPath($this->path('bootstrap'));
        $this->app->useStoragePath($this->path('storage'));
        file_put_contents($this->path('bootstrap/cache/config.php'), '<?php return [];');
        file_put_contents($this->path('bootstrap/cache/routes-v7.php'), '<?php');

        // Every view of the application compiled where the framework looks
        // for it, as view:cache leaves them.
        $compiler = new BladeCompiler($this->app->make('files'), $this->path('views'));

        foreach (File::allFiles(resource_path('views')) as $view) {
            if (str_ends_with($view->getFilename(), '.blade.php')) {
                file_put_contents($compiler->getCompiledPath((string) $view->getRealPath()), '<?php');
            }
        }

        // The account of the application owns exactly what it needs.
        $this->grants = [
            'GRANT USAGE ON *.* TO `torsade_app`@`localhost`',
            sprintf('GRANT SELECT, INSERT, UPDATE, DELETE ON `%s`.* TO `torsade_app`@`localhost`', self::DATABASE),
        ];

        $this->app->bind(ProductionChecks::class, function (Application $app): ProductionChecks {
            $test = $this;

            return new class($app, $this->path('data'), $this->path('logs'), $test) extends ProductionChecks
            {
                public function __construct(Application $app, string $data, string $logs, private readonly CheckCommandTest $test)
                {
                    parent::__construct($app, $data, $logs);
                }

                protected function grants(): array
                {
                    return $this->test->grantsOfTheAccount();
                }

                protected function onLinux(): bool
                {
                    return $this->test->checksTheRightsOfTheFiles();
                }
            };
        });
    }

    /**
     * @return list<string>
     */
    public function grantsOfTheAccount(): array
    {
        return $this->grants;
    }

    public function checksTheRightsOfTheFiles(): bool
    {
        return $this->linux;
    }

    private function path(string $path = ''): string
    {
        return $this->folder.($path === '' ? '' : DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path));
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function check(): array
    {
        $code = Artisan::call('torsade:check');

        return [$code, Artisan::output()];
    }

    private function assertLine(string $output, string $name, string $outcome): void
    {
        $this->assertMatchesRegularExpression(
            '/'.preg_quote($name, '/').'\s*\.*\s*'.$outcome.'/u',
            $output,
            "The line \"{$name}\" is not {$outcome}.",
        );
    }

    public function test_an_installation_as_the_installer_leaves_it_passes_every_check(): void
    {
        [$code, $output] = $this->check();

        $this->assertSame(0, $code, $output);

        foreach ([
            'APP_ENV vaut production',
            'APP_DEBUG est désactivé',
            'APP_KEY est défini',
            'APP_URL est en HTTPS',
            'Le cookie de session est Secure, préfixé par __Host-, sur le chemin / et sans domaine',
            'La configuration est en cache',
            'Les routes sont en cache',
            'Les vues sont en cache',
            'Le cache de configuration est plus récent que .env',
            "storage/ est modifiable par l'utilisateur qui lance cette commande",
            "bootstrap/cache/ est modifiable par l'utilisateur qui lance cette commande",
            'La base de données est joignable',
            'Toutes les migrations sont passées',
            sprintf("Le compte de la base n'a que SELECT, INSERT, UPDATE et DELETE sur %s", self::DATABASE),
        ] as $name) {
            $this->assertLine($output, $name, 'réussi');
        }

        $this->assertStringContainsString('Tous les contrôles ont réussi.', $output);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function failures(): array
    {
        return [
            'environment' => ['environment', 'APP_ENV vaut production', 'Mettez APP_ENV à production dans .env'],
            'debug' => ['debug', 'APP_DEBUG est désactivé', 'Mettez APP_DEBUG à false dans .env'],
            'key' => ['key', 'APP_KEY est défini', 'php artisan key:generate'],
            'url' => ['url', 'APP_URL est en HTTPS', "Mettez dans APP_URL l'adresse HTTPS"],
            'url without a host' => ['url without a host', 'APP_URL est en HTTPS', "Mettez dans APP_URL l'adresse HTTPS"],
            'cookie' => ['cookie', 'Le cookie de session est Secure, préfixé par __Host-, sur le chemin / et sans domaine', "Son nom ne commence pas par __Host-, il n'est pas Secure, son chemin n'est pas /, il a un domaine."],
            'configuration cache' => ['configuration cache', 'La configuration est en cache', 'Lancez php artisan config:cache'],
            'routes cache' => ['routes cache', 'Les routes sont en cache', 'Lancez php artisan route:cache'],
            'views cache' => ['views cache', 'Les vues sont en cache', 'Lancez php artisan view:cache'],
            'views folder' => ['views folder', 'Les vues sont en cache', 'Lancez php artisan view:cache'],
            'stale configuration' => ['stale configuration', 'Le cache de configuration est plus récent que .env', 'après toute modification de .env'],
            'storage' => ['storage', "storage/ est modifiable par l'utilisateur qui lance cette commande", "le droit d'écrire dans"],
            'bootstrap cache' => ['bootstrap cache', "bootstrap/cache/ est modifiable par l'utilisateur qui lance cette commande", "le droit d'écrire dans"],
            'database' => ['database', 'La base de données est joignable', 'Vérifiez les réglages de la base dans .env'],
            'migrations' => ['migrations', 'Toutes les migrations sont passées', "1 migration n'est pas passée"],
            'privileges' => ['privileges', "Le compte de la base n'a que SELECT, INSERT, UPDATE et DELETE sur torsade_test", 'En plus sur torsade_test : CREATE, DROP'],
        ];
    }

    #[DataProvider('failures')]
    public function test_each_check_fails_and_says_what_to_correct(string $breakage, string $name, string $correction): void
    {
        $this->break($breakage);

        try {
            [$code, $output] = $this->check();
        } finally {
            config(['database.default' => 'mariadb']);
        }

        $this->assertSame(1, $code, $output);
        $this->assertLine($output, $name, 'échoué');
        $this->assertStringContainsString($correction, $output);
        $this->assertMatchesRegularExpression('/\d+ contrôles? (?:a|ont) échoué\./u', $output);
    }

    private function break(string $what): void
    {
        $connection = (string) config('database.default');

        match ($what) {
            'environment' => config(['app.env' => 'local']),
            'debug' => config(['app.debug' => true]),
            'key' => config(['app.key' => '']),
            'url' => config(['app.url' => 'http://torsade.example.test']),
            'url without a host' => config(['app.url' => 'https://']),
            'cookie' => config(['session.cookie' => 'torsade-session', 'session.secure' => null, 'session.path' => '/torsade', 'session.domain' => 'example.test']),
            'configuration cache' => unlink($this->path('bootstrap/cache/config.php')),
            'routes cache' => unlink($this->path('bootstrap/cache/routes-v7.php')),
            // One view left out, as when a page opened compiled only its own.
            'views cache' => unlink((string) (glob($this->path('views').DIRECTORY_SEPARATOR.'*.php') ?: [''])[0]),
            // No folder of compiled views: the framework then gives none.
            'views folder' => config(['view.compiled' => '']),
            'stale configuration' => touch($this->path('.env'), time() + 60),
            'storage' => $this->app->useStoragePath($this->path('nowhere')),
            'bootstrap cache' => $this->app->useBootstrapPath($this->path('nowhere')),
            'database' => config([
                'database.connections.unreachable' => [...(array) config("database.connections.{$connection}"), 'host' => '127.0.0.1', 'port' => 1],
                'database.default' => 'unreachable',
            ]),
            'migrations' => $this->leaveAMigrationPending(),
            'privileges' => $this->grants[] = sprintf('GRANT CREATE, DROP ON `%s`.* TO `torsade_app`@`localhost`', self::DATABASE),
        };
    }

    private function leaveAMigrationPending(): void
    {
        file_put_contents($this->path('migrations/2099_01_01_000000_pending_change.php'), '<?php');

        $this->app->make('migrator')->path($this->path('migrations'));
    }

    public function test_the_rights_on_the_files_are_left_out_on_another_system_than_linux(): void
    {
        [, $output] = $this->check();

        $this->assertStringNotContainsString('fermé aux autres utilisateurs', $output);
        $this->assertStringNotContainsString('Les fichiers de cache sont fermés', $output);
    }

    public function test_on_linux_the_files_and_folders_closed_to_the_other_users_pass_and_open_ones_fail(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('The rights on the files are those of Linux: the CI checks them.');
        }

        $this->linux = true;

        chmod($this->path('.env'), 0640);
        chmod($this->path('bootstrap/cache/config.php'), 0640);
        chmod($this->path('bootstrap/cache/routes-v7.php'), 0640);
        chmod($this->path('data'), 0750);
        chmod($this->path('logs'), 0750);

        [$code, $output] = $this->check();

        $this->assertSame(0, $code, $output);
        $this->assertLine($output, '.env est fermé aux autres utilisateurs', 'réussi');
        $this->assertLine($output, 'Les fichiers de cache sont fermés aux autres utilisateurs', 'réussi');
        $this->assertLine($output, $this->path('data').' est fermé aux autres utilisateurs', 'réussi');
        $this->assertLine($output, $this->path('logs').' est fermé aux autres utilisateurs', 'réussi');

        chmod($this->path('.env'), 0644);
        chmod($this->path('bootstrap/cache/config.php'), 0644);
        chmod($this->path('data'), 0755);
        chmod($this->path('logs'), 0751);

        [$code, $output] = $this->check();

        $this->assertSame(1, $code, $output);
        $this->assertLine($output, '.env est fermé aux autres utilisateurs', 'échoué');
        $this->assertStringContainsString('Lancez chmod o-rwx '.$this->path('.env'), $output);
        $this->assertLine($output, 'Les fichiers de cache sont fermés aux autres utilisateurs', 'échoué');
        $this->assertStringContainsString('Lancez chmod 0640 sur '.$this->path('bootstrap/cache/config.php'), $output);
        $this->assertLine($output, $this->path('data').' est fermé aux autres utilisateurs', 'échoué');
        $this->assertLine($output, $this->path('logs').' est fermé aux autres utilisateurs', 'échoué');

        File::deleteDirectory($this->path('logs'));

        [, $output] = $this->check();

        // The bullets of the console of Laravel lose their final period.
        $this->assertStringContainsString(sprintf("Le chemin %s n'existe pas : l'installation le crée", $this->path('logs')), $output);
    }

    public function test_a_file_of_the_storage_the_user_cannot_write_fails_its_check(): void
    {
        // As a command run as root would leave it.
        $file = $this->path('storage/logs/laravel.log');

        File::ensureDirectoryExists(dirname($file));
        file_put_contents($file, '');
        chmod($file, 0444);

        try {
            [$code, $output] = $this->check();
        } finally {
            chmod($file, 0644);
        }

        $this->assertSame(1, $code, $output);
        $this->assertLine($output, "storage/ est modifiable par l'utilisateur qui lance cette commande", 'échoué');
        $this->assertStringContainsString($file, $output);
    }

    public function test_no_secret_value_is_ever_printed(): void
    {
        // The key stays set while every line that reads the configuration,
        // the connection and the privileges fails and explains itself; the
        // server gives the hash of the password with a global privilege.
        $hash = '*0123456789ABCDEF0123456789ABCDEF01234567';

        $this->break('debug');
        $this->grants[] = sprintf("GRANT RELOAD ON *.* TO `torsade_app`@`localhost` IDENTIFIED BY PASSWORD '%s'", $hash);

        [$code, $privileges] = $this->check();

        $this->assertSame(1, $code, $privileges);
        $this->assertStringContainsString('RELOAD', $privileges);

        $this->break('database');

        try {
            [, $database] = $this->check();
        } finally {
            config(['database.default' => 'mariadb']);
        }

        foreach ([$privileges, $database] as $output) {
            $this->assertStringNotContainsString(self::KEY, $output);
            $this->assertStringNotContainsString('c2VjcmV0', $output);
            $this->assertStringNotContainsString(self::DATABASE_PASSWORD, $output);
            $this->assertStringNotContainsString($hash, $output);
            $this->assertStringNotContainsString('0123456789ABCDEF', $output);
        }
    }

    public function test_the_grants_of_the_account_are_read_from_the_server(): void
    {
        $checks = new class($this->app) extends ProductionChecks
        {
            /**
             * @return list<string>
             */
            public function read(): array
            {
                return $this->grants();
            }
        };

        $grants = $checks->read();

        $this->assertNotEmpty($grants);

        foreach ($grants as $grant) {
            $this->assertStringStartsWith('GRANT ', $grant);
        }
    }

    public function test_the_command_writes_nothing(): void
    {
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $files = fn (): array => collect(File::allFiles($this->folder))
            ->mapWithKeys(fn ($file): array => [$file->getPathname() => [$file->getMTime(), $file->getSize()]])
            ->all();

        $before = $files();

        $this->check();

        $this->assertSame($before, $files());
        $this->assertNotEmpty($queries);

        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^\s*(select|show)\b/i', $sql, "The command ran: {$sql}");
        }
    }
}
