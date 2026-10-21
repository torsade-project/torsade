<?php

// torsade/tests/Feature/Console/PurgeHealthCacheCommandTest.php

namespace Tests\Feature\Console;

use App\Http\Controllers\HealthController;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PurgeHealthCacheCommandTest extends TestCase
{
    private string $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = sys_get_temp_dir().'/torsade-health-'.bin2hex(random_bytes(4));

        config([
            'cache.stores.'.HealthController::LIMITER_STORE => [
                'driver' => 'file',
                'path' => $this->store,
                'lock_path' => $this->store,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->store);

        parent::tearDown();
    }

    public function test_only_expired_entries_are_removed(): void
    {
        $limiter = new RateLimiter(Cache::store(HealthController::LIMITER_STORE));

        // Three current entries. The limiter stores them with a one-minute
        // expiration, so none has expired yet.
        foreach (['1.1.1.1', '2.2.2.2', '3.3.3.3'] as $address) {
            $limiter->hit('health:'.$address, 60);
        }

        $this->assertGreaterThan(0, $this->fileCount($this->store));
        $currentCount = $this->fileCount($this->store);

        // One expired file: the first ten characters are a UNIX timestamp
        // in the past, followed by the stored value. The format is what
        // the file cache driver writes.
        $expiredPath = $this->store.'/'.sha1('health:4.4.4.4');
        File::ensureDirectoryExists(dirname($expiredPath));
        file_put_contents($expiredPath, (time() - 120).'s:1:"1";');

        $this->artisan('torsade:purge-health-cache')
            ->assertSuccessful()
            ->expectsOutputToContain('1 entrée expirée retirée du cache du point de contrôle.');

        $this->assertFileDoesNotExist($expiredPath);
        $this->assertSame($currentCount, $this->fileCount($this->store));
    }

    public function test_the_command_touches_nothing_else_than_the_cache_folder(): void
    {
        File::ensureDirectoryExists($this->store);
        file_put_contents($this->store.'/sibling.txt', '(time() - 1).');

        $this->artisan('torsade:purge-health-cache')->assertSuccessful();

        // A file whose first ten bytes are not a UNIX timestamp is left
        // alone, even in the folder.
        $this->assertFileExists($this->store.'/sibling.txt');
    }

    public function test_the_command_succeeds_on_an_empty_store(): void
    {
        // The folder is created on the first write by the file cache; the
        // command must not fail when it has yet to be created.
        $this->artisan('torsade:purge-health-cache')->assertSuccessful();
    }

    public function test_the_count_of_the_entries_removed_is_told_in_the_singular_or_the_plural(): void
    {
        File::ensureDirectoryExists($this->store);

        $this->artisan('torsade:purge-health-cache')
            ->assertSuccessful()
            ->expectsOutputToContain('0 entrée expirée retirée du cache du point de contrôle.');

        foreach (['5.5.5.5', '6.6.6.6'] as $address) {
            file_put_contents($this->store.'/'.sha1('health:'.$address), (time() - 120).'s:1:"1";');
        }

        $this->artisan('torsade:purge-health-cache')
            ->assertSuccessful()
            ->expectsOutputToContain('2 entrées expirées retirées du cache du point de contrôle.');
    }

    private function fileCount(string $dir): int
    {
        if (! is_dir($dir)) {
            return 0;
        }

        $count = 0;

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }

        return $count;
    }
}
