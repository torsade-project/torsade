<?php

// torsade/tests/Feature/TrustedHostsAndProxiesTest.php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * In production, the application answers only to its own host, and only
 * believes the forwarded headers of the proxies it is told about. Both
 * settings come from the configuration, read on every request, so that a
 * cached configuration still holds them.
 */
class TrustedHostsAndProxiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://torsade.example.test', 'app.debug' => false]);
    }

    /**
     * The check of the Host header, which the framework turns off in tests
     * and in the local environment, turned on as in production. The hosts
     * are those the application gives.
     */
    private function checkTheHost(): void
    {
        $this->app->bind(TrustHosts::class, fn (Application $app): TrustHosts => new class($app) extends TrustHosts
        {
            protected function shouldSpecifyTrustedHosts(): bool
            {
                return true;
            }
        });
    }

    /**
     * @return TestResponse<Response>
     */
    private function loginPageOn(string $host): TestResponse
    {
        return $this->get("https://{$host}/login");
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function assertRefusedWithoutDetail(TestResponse $response): void
    {
        // A bad request, on the page whose title the troubleshooting of the
        // administration quotes.
        $response->assertStatus(400);

        $content = (string) $response->getContent();

        $this->assertStringContainsString('<title>La requête a été refusée - Torsade</title>', $content);
        $this->assertStringContainsString('Erreur 400', $content);

        $this->assertStringNotContainsString('torsade.example.test', $content);
        $this->assertStringNotContainsString('Untrusted', $content);
        $this->assertStringNotContainsString('Suspicious', $content);

        // Refused before any route, it still carries the security headers.
        $this->assertNotEmpty($response->headers->get('Content-Security-Policy'));
    }

    // --- Hosts ----------------------------------------------------------------

    public function test_the_host_of_app_url_is_answered(): void
    {
        $this->checkTheHost();

        $this->loginPageOn('torsade.example.test')->assertOk();
    }

    public function test_another_host_is_refused_with_a_400_without_detail(): void
    {
        $this->checkTheHost();

        $this->assertRefusedWithoutDetail($this->loginPageOn('autre.example.test'));
    }

    public function test_a_subdomain_of_the_host_of_app_url_is_refused(): void
    {
        $this->checkTheHost();

        $this->assertRefusedWithoutDetail($this->loginPageOn('www.torsade.example.test'));
    }

    public function test_a_host_that_only_contains_the_one_of_app_url_is_refused(): void
    {
        $this->checkTheHost();

        $this->assertRefusedWithoutDetail($this->loginPageOn('torsade.example.test.autre.example'));
        $this->assertRefusedWithoutDetail($this->loginPageOn('torsadeXexample.test'));
    }

    public function test_the_hosts_of_the_configuration_are_answered_and_none_other(): void
    {
        config(['security.trusted_hosts' => ['192.0.2.10', 'secours.example.test']]);
        $this->checkTheHost();

        $this->loginPageOn('192.0.2.10')->assertOk();
        $this->loginPageOn('secours.example.test')->assertOk();
        $this->loginPageOn('torsade.example.test')->assertOk();
        $this->assertRefusedWithoutDetail($this->loginPageOn('192.0.2.100'));
    }

    // --- Proxies --------------------------------------------------------------

    /**
     * Five failed logins on one account, each one announcing another
     * address through X-Forwarded-For.
     *
     * @param  list<string>  $announced
     */
    private function failFiveTimes(User $user, array $announced): void
    {
        foreach ($announced as $address) {
            $this->withHeader('X-Forwarded-For', $address)
                ->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
                ->assertSessionHasErrors('email');
        }
    }

    private function logInFrom(User $user, string $announced): void
    {
        $this->withHeader('X-Forwarded-For', $announced)
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    }

    public function test_without_a_trusted_proxy_login_attempts_count_by_the_real_address(): void
    {
        $user = User::factory()->create();

        // Whatever the requests announce, they all come from one address:
        // its five failures lock the account for it.
        $this->failFiveTimes($user, ['203.0.113.1', '203.0.113.2', '203.0.113.3', '203.0.113.4', '203.0.113.5']);
        $this->logInFrom($user, '203.0.113.6');

        $this->assertGuest();
    }

    public function test_with_a_trusted_proxy_the_address_it_announces_counts(): void
    {
        // The requests of the tests come from 127.0.0.1, here the proxy.
        config(['trustedproxy.proxies' => ['127.0.0.1']]);

        $user = User::factory()->create();

        $this->failFiveTimes($user, array_fill(0, 5, '203.0.113.7'));

        // Locked for the address that failed, not for another one.
        $this->logInFrom($user, '203.0.113.7');
        $this->assertGuest();

        $this->logInFrom($user, '203.0.113.8');
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_scheme_a_proxy_announces_counts_only_when_the_proxy_is_trusted(): void
    {
        config(['security.hsts.enabled' => true]);

        // Over plain HTTP, an announced HTTPS is not believed: no HSTS.
        $this->withHeader('X-Forwarded-Proto', 'https')->get('http://torsade.example.test/login')
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security');

        config(['trustedproxy.proxies' => ['127.0.0.1']]);

        $this->withHeader('X-Forwarded-Proto', 'https')->get('http://torsade.example.test/login')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    // --- Cached configuration ---------------------------------------------------

    public function test_a_cached_configuration_holds_the_hosts_the_proxies_and_hsts(): void
    {
        // The cache is written by the command itself, in a temporary folder:
        // never in bootstrap/cache, where it would freeze the database of
        // the development environment.
        $folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'torsade-config-cache-'.bin2hex(random_bytes(4));
        $cache = $folder.DIRECTORY_SEPARATOR.'config.php';
        $repositoryCache = base_path('bootstrap/cache/config.php');
        $repositoryCacheWasThere = is_file($repositoryCache);

        mkdir($folder);

        try {
            $result = Process::path(base_path())->env([
                'APP_CONFIG_CACHE' => $this->pathFromTheApplication($cache),
                'APP_ENV' => 'testing',
                'DB_DATABASE' => self::DATABASE,
                'HSTS_ENABLED' => 'true',
                'TRUSTED_HOSTS' => 'secours.example.test, 192.0.2.10',
                'TRUSTED_PROXIES' => '192.0.2.1,198.51.100.0/24',
            ])->run([PHP_BINARY, 'artisan', 'config:cache']);

            $this->assertTrue($result->successful(), $result->output().$result->errorOutput());
            $this->assertFileExists($cache);
            $this->assertFileDoesNotExist($repositoryCache);

            /** @var array<string, array<string, mixed>> $cached */
            $cached = require $cache;

            $this->assertTrue($cached['security']['hsts']['enabled']);
            $this->assertSame(['secours.example.test', '192.0.2.10'], $cached['security']['trusted_hosts']);
            $this->assertSame(['192.0.2.1', '198.51.100.0/24'], $cached['trustedproxy']['proxies']);
        } finally {
            if (is_file($cache)) {
                unlink($cache);
            }

            rmdir($folder);

            // Had the command ignored the temporary folder, the cache it
            // wrote in the repository would freeze the configuration of
            // every later test: it goes, unless it was there before.
            if (! $repositoryCacheWasThere && is_file($repositoryCache)) {
                unlink($repositoryCache);
            }
        }
    }

    /**
     * A path the framework accepts as the place of its cache. It takes a
     * path that starts with a slash as it is, and puts any other one under
     * the folder of the application: a Windows path, which starts with its
     * drive, is given relative to that folder.
     */
    private function pathFromTheApplication(string $path): string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return $path;
        }

        $from = explode(DIRECTORY_SEPARATOR, rtrim((string) realpath(base_path()), DIRECTORY_SEPARATOR));
        $to = explode(DIRECTORY_SEPARATOR, (string) realpath(dirname($path)));

        while ($from !== [] && $to !== [] && strcasecmp($from[0], $to[0]) === 0) {
            array_shift($from);
            array_shift($to);
        }

        return implode(DIRECTORY_SEPARATOR, [...array_fill(0, count($from), '..'), ...$to, basename($path)]);
    }

    public function test_no_security_setting_is_read_from_the_environment_outside_of_the_configuration(): void
    {
        $sources = [
            base_path('bootstrap/app.php'),
            ...glob(app_path('{,*/,*/*/,*/*/*/}*.php'), GLOB_BRACE) ?: [],
        ];

        foreach ($sources as $source) {
            $this->assertDoesNotMatchRegularExpression(
                '/\benv\(\s*[\'"](?:HSTS_ENABLED|TRUSTED_HOSTS|TRUSTED_PROXIES)[\'"]/',
                (string) file_get_contents($source),
                "{$source} reads a security setting from the environment.",
            );
        }
    }
}
