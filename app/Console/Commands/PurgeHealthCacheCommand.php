<?php

// torsade/app/Console/Commands/PurgeHealthCacheCommand.php

namespace App\Console\Commands;

use App\Http\Controllers\HealthController;
use FilesystemIterator;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Deletes the expired entries of the file cache that counts the calls to
 * /up. Each address that calls it leaves a file in the store, and none is
 * read again once its minute has passed: without this command the folder
 * grows forever. Only expired entries are removed; current ones stay, so a
 * probe that is still within its minute keeps its counter. A daily timer
 * of the system, installed with the application, runs it.
 */
class PurgeHealthCacheCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'torsade:purge-health-cache';

    /**
     * @var string
     */
    protected $description = 'Remove expired entries from the health check cache';

    public function handle(): int
    {
        $store = (string) config('cache.stores.'.HealthController::LIMITER_STORE.'.path');

        if ($store === '' || ! is_dir($store)) {
            // Nothing has been written yet: the first call to /up creates
            // the folder. Not an error.
            return self::SUCCESS;
        }

        $now = time();
        $removed = 0;

        /** @var SplFileInfo $file */
        foreach ($this->iterate($store) as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $expiration = $this->expirationOf($file->getPathname());

            // Laravel writes a very large expiration (9999999999) for a
            // forever entry: those are kept.
            if ($expiration !== null && $expiration > 0 && $expiration < $now) {
                @unlink($file->getPathname());
                $removed++;
            }
        }

        $this->components->info(trans_choice(
            ':count expired entry removed from the cache of the health check.|:count expired entries removed from the cache of the health check.',
            $removed,
        ));

        return self::SUCCESS;
    }

    /**
     * @return RecursiveIteratorIterator<RecursiveDirectoryIterator>
     */
    private function iterate(string $path): RecursiveIteratorIterator
    {
        return new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );
    }

    /**
     * Laravel's file cache writes "<10 digits of UNIX timestamp><serialized
     * value>" at the start of every file. Any other content is foreign to
     * the store and left alone.
     */
    private function expirationOf(string $path): ?int
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            $head = fread($handle, 10);
        } finally {
            fclose($handle);
        }

        return is_string($head) && strlen($head) === 10 && ctype_digit($head)
            ? (int) $head
            : null;
    }
}
