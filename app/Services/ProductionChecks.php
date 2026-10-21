<?php

// torsade/app/Services/ProductionChecks.php

namespace App\Services;

use App\Support\DatabaseAccountPrivileges;
use App\Support\ProductionCheck;
use FilesystemIterator;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\View\Compilers\BladeCompiler;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * The checks of a production installation: the environment, the session
 * cookie, the caches, the rights on the files, the database and the
 * privileges of its account. Each one reads, and none writes; none puts a
 * secret value in what it says. The rights on the files are those of
 * Linux: on another system, these checks are left out.
 */
class ProductionChecks
{
    /**
     * Where an installation keeps its data and its logs.
     */
    public const string DATA_PATH = '/var/lib/torsade';

    public const string LOG_PATH = '/var/log/torsade';

    public function __construct(
        private readonly Application $app,
        private readonly string $dataPath = self::DATA_PATH,
        private readonly string $logPath = self::LOG_PATH,
    ) {}

    /**
     * @return list<ProductionCheck>
     */
    public function all(): array
    {
        return array_values(array_filter([
            $this->environment(),
            $this->debug(),
            $this->key(),
            $this->url(),
            $this->sessionCookie(),
            $this->configurationCached(),
            $this->routesCached(),
            $this->viewsCached(),
            $this->configurationCacheFresh(),
            $this->writable(storage_path(), 'storage/'),
            $this->writable($this->app->bootstrapPath('cache'), 'bootstrap/cache/'),
            ...($this->onLinux() ? [
                $this->closed($this->app->environmentFilePath(), '.env'),
                $this->cacheFilesClosed(),
                $this->closed($this->dataPath, $this->dataPath),
                $this->closed($this->logPath, $this->logPath),
            ] : []),
            $this->databaseReachable(),
            $this->migrationsRun(),
            $this->databasePrivileges(),
        ]));
    }

    /**
     * Whether the checks of the rights on the files apply.
     */
    protected function onLinux(): bool
    {
        return PHP_OS_FAMILY === 'Linux';
    }

    private function environment(): ProductionCheck
    {
        return config('app.env') === 'production'
            ? ProductionCheck::pass(__('APP_ENV is production'))
            : ProductionCheck::fail(__('APP_ENV is production'), __('Set APP_ENV to production in .env, then rebuild the configuration cache.'));
    }

    private function debug(): ProductionCheck
    {
        return config('app.debug') === false
            ? ProductionCheck::pass(__('APP_DEBUG is off'))
            : ProductionCheck::fail(__('APP_DEBUG is off'), __('Set APP_DEBUG to false in .env, then rebuild the configuration cache.'));
    }

    private function key(): ProductionCheck
    {
        return (string) config('app.key') !== ''
            ? ProductionCheck::pass(__('APP_KEY is set'))
            : ProductionCheck::fail(__('APP_KEY is set'), __('Generate it with php artisan key:generate, then rebuild the configuration cache.'));
    }

    /**
     * In HTTPS, and with a host: without one, the check of the Host header
     * would have no host to compare with.
     */
    private function url(): ProductionCheck
    {
        $url = (string) config('app.url');

        return str_starts_with(strtolower($url), 'https://') && (string) parse_url($url, PHP_URL_HOST) !== ''
            ? ProductionCheck::pass(__('APP_URL is in HTTPS'))
            : ProductionCheck::fail(__('APP_URL is in HTTPS'), __('Set APP_URL to the HTTPS address of the application in .env, then rebuild the configuration cache.'));
    }

    private function sessionCookie(): ProductionCheck
    {
        $name = __('The session cookie is Secure, prefixed with __Host-, on the path / and without a domain');
        $wrong = array_keys(array_filter([
            __('its name does not start with __Host-') => ! str_starts_with((string) config('session.cookie'), '__Host-'),
            __('it is not Secure') => config('session.secure') !== true,
            __('its path is not /') => config('session.path') !== '/',
            __('it has a domain') => ! in_array(config('session.domain'), [null, ''], true),
        ]));

        return $wrong === []
            ? ProductionCheck::pass($name)
            : ProductionCheck::fail($name, __(':wrong. Check SESSION_COOKIE, SESSION_SECURE_COOKIE, SESSION_PATH and SESSION_DOMAIN in .env.', [
                'wrong' => ucfirst(implode(', ', $wrong)),
            ]));
    }

    private function configurationCached(): ProductionCheck
    {
        return is_file($this->app->getCachedConfigPath())
            ? ProductionCheck::pass(__('The configuration is cached'))
            : ProductionCheck::fail(__('The configuration is cached'), __('Run php artisan config:cache as the user of the application.'));
    }

    private function routesCached(): ProductionCheck
    {
        return is_file($this->app->getCachedRoutesPath())
            ? ProductionCheck::pass(__('The routes are cached'))
            : ProductionCheck::fail(__('The routes are cached'), __('Run php artisan route:cache as the user of the application.'));
    }

    /**
     * Every view of the application compiled where the framework looks for
     * it. A page opened compiles its own views: a few compiled views prove
     * nothing about the others.
     */
    private function viewsCached(): ProductionCheck
    {
        // The framework gives no folder when the one of the compiled views
        // is missing.
        if ((string) config('view.compiled') === '') {
            return ProductionCheck::fail(__('The views are cached'), __('Run php artisan view:cache as the user of the application.'));
        }

        $files = $this->app->make('files');
        $compiler = new BladeCompiler(
            $files,
            (string) config('view.compiled'),
            config('view.relative_hash', false) ? $this->app->basePath() : '',
            true,
            (string) config('view.compiled_extension', 'php'),
        );
        $views = 0;
        $missing = 0;

        foreach ((array) config('view.paths') as $path) {
            if (! is_string($path) || ! is_dir($path)) {
                continue;
            }

            foreach ($files->allFiles($path) as $file) {
                if (! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }

                $views++;

                if (! is_file($compiler->getCompiledPath((string) $file->getRealPath()))) {
                    $missing++;
                }
            }
        }

        return $views > 0 && $missing === 0
            ? ProductionCheck::pass(__('The views are cached'))
            : ProductionCheck::fail(__('The views are cached'), __('Run php artisan view:cache as the user of the application.'));
    }

    /**
     * Only when both files are there: a missing cache is told above.
     */
    private function configurationCacheFresh(): ?ProductionCheck
    {
        $cache = $this->app->getCachedConfigPath();
        $environment = $this->app->environmentFilePath();

        if (! is_file($cache) || ! is_file($environment)) {
            return null;
        }

        return filemtime($cache) >= filemtime($environment)
            ? ProductionCheck::pass(__('The configuration cache is more recent than .env'))
            : ProductionCheck::fail(__('The configuration cache is more recent than .env'), __('Rebuild it with php artisan config:cache after any change to .env.'));
    }

    private function writable(string $path, string $label): ProductionCheck
    {
        $name = __(':path can be written by the user running this command', ['path' => $label]);
        $blocked = $this->firstNotWritable($path);

        return $blocked === null
            ? ProductionCheck::pass($name)
            : ProductionCheck::fail($name, __('Give the user of the application the right to write in :path.', ['path' => $blocked]));
    }

    /**
     * The folder itself, or the first folder or file under it, that the user
     * running the command cannot write: a file a command run as root left
     * in the storage is one PHP-FPM cannot write either.
     */
    private function firstNotWritable(string $path): ?string
    {
        if (! is_dir($path) || ! is_writable($path)) {
            return $path;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
            RecursiveIteratorIterator::SELF_FIRST,
            RecursiveIteratorIterator::CATCH_GET_CHILD,
        );

        foreach ($entries as $entry) {
            /** @var SplFileInfo $entry */
            if (! is_writable($entry->getPathname())) {
                return $entry->getPathname();
            }
        }

        return null;
    }

    /**
     * A file or a folder neither readable, nor writable, nor open to the
     * users who are neither its owner nor in its group.
     */
    private function closed(string $path, string $label): ProductionCheck
    {
        $name = __(':path is closed to the other users', ['path' => $label]);

        if (! file_exists($path)) {
            return ProductionCheck::fail($name, __(':path does not exist: the installation creates it.', ['path' => $path]));
        }

        return $this->closedToOthers($path)
            ? ProductionCheck::pass($name)
            : ProductionCheck::fail($name, __('Run chmod o-rwx :path.', ['path' => $path]));
    }

    private function cacheFilesClosed(): ProductionCheck
    {
        $name = __('The cache files are closed to the other users');
        $open = array_values(array_filter(
            glob($this->app->bootstrapPath('cache').DIRECTORY_SEPARATOR.'*.php') ?: [],
            fn (string $file): bool => ! $this->closedToOthers($file),
        ));

        return $open === []
            ? ProductionCheck::pass($name)
            : ProductionCheck::fail($name, __('Run chmod 0640 on :files.', ['files' => implode(', ', $open)]));
    }

    private function closedToOthers(string $path): bool
    {
        $mode = @fileperms($path);

        return $mode !== false && ($mode & 0o007) === 0;
    }

    private function databaseReachable(): ProductionCheck
    {
        try {
            DB::connection()->getPdo();

            return ProductionCheck::pass(__('The database is reachable'));
        } catch (Throwable) {
            return ProductionCheck::fail(__('The database is reachable'), __('Check the settings of the database in .env, and that MariaDB runs.'));
        }
    }

    private function migrationsRun(): ProductionCheck
    {
        $name = __('Every migration has run');

        try {
            /** @var Migrator $migrator */
            $migrator = $this->app->make('migrator');
            $files = $migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]);
            $pending = array_diff(array_keys($files), $migrator->getRepository()->getRan());

            return $pending === []
                ? ProductionCheck::pass($name)
                : ProductionCheck::fail($name, trans_choice(':count migration has not run: the update script runs it.|:count migrations have not run: the update script runs them.', count($pending)));
        } catch (Throwable) {
            return ProductionCheck::fail($name, __('The migrations could not be read: check that the database is reachable.'));
        }
    }

    private function databasePrivileges(): ProductionCheck
    {
        $database = (string) DB::connection()->getDatabaseName();
        $name = __('The account of the database only has SELECT, INSERT, UPDATE and DELETE on :database', ['database' => $database]);

        try {
            $assessment = DatabaseAccountPrivileges::assess($this->grants(), $database);
        } catch (Throwable) {
            return ProductionCheck::fail($name, __('The privileges of the account could not be read: check that the database is reachable.'));
        }

        if (DatabaseAccountPrivileges::exact($assessment)) {
            return ProductionCheck::pass($name);
        }

        $wrong = array_filter([
            $assessment['extra'] === [] ? '' : (string) __('more on :database: :privileges', ['database' => $database, 'privileges' => implode(', ', $assessment['extra'])]),
            $assessment['missing'] === [] ? '' : (string) __('missing on :database: :privileges', ['database' => $database, 'privileges' => implode(', ', $assessment['missing'])]),
            $assessment['elsewhere'] === [] ? '' : (string) __('on something else: :grants', ['grants' => implode('; ', $assessment['elsewhere'])]),
            $assessment['roles'] === [] ? '' : (string) __('roles: :roles', ['roles' => implode(', ', $assessment['roles'])]),
            $assessment['grantOption'] ? (string) __('it may grant its privileges') : '',
        ]);

        return ProductionCheck::fail($name, __(':wrong. The account of the application only needs these four privileges; the schema is changed by the account kept for the installation and the updates.', [
            'wrong' => ucfirst(implode('; ', $wrong)),
        ]));
    }

    /**
     * The lines SHOW GRANTS gives for the account of the application, and
     * for every account (PUBLIC) when the server keeps privileges for them.
     * Reading only: nothing is written.
     *
     * @return list<string>
     */
    protected function grants(): array
    {
        $connection = DB::connection();
        $lines = $connection->select('SHOW GRANTS FOR CURRENT_USER');

        try {
            $lines = [...$lines, ...$connection->select('SHOW GRANTS FOR PUBLIC')];
        } catch (Throwable) {
            // No privilege kept for every account.
        }

        return array_map(
            fn (object $line): string => (string) (array_values((array) $line)[0] ?? ''),
            array_values($lines),
        );
    }
}
