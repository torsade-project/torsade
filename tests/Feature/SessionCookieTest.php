<?php

// torsade/tests/Feature/SessionCookieTest.php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class SessionCookieTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The same driver as a real installation, so that a cookie is issued.
        config(['session.driver' => 'database']);
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function sessionCookie(TestResponse $response): Cookie
    {
        $cookie = collect($response->headers->getCookies())
            ->first(fn (Cookie $cookie): bool => $cookie->getName() === config('session.cookie'));

        $this->assertInstanceOf(Cookie::class, $cookie, 'No session cookie was issued.');

        return $cookie;
    }

    public function test_the_session_cookie_is_http_only_and_same_site_lax(): void
    {
        $cookie = $this->sessionCookie($this->get(route('login')));

        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    public function test_http_only_and_same_site_cannot_be_relaxed_by_the_environment(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));

        $config = file_get_contents(config_path('session.php'));

        $this->assertStringNotContainsString('SESSION_HTTP_ONLY', $config);
        $this->assertStringNotContainsString('SESSION_SAME_SITE', $config);
    }

    public function test_the_cookie_is_not_secure_over_plain_http_by_default(): void
    {
        $this->assertNull(config('session.secure'));
        $this->assertFalse($this->sessionCookie($this->get('http://torsade.test/login'))->isSecure());
    }

    public function test_the_cookie_is_secure_over_https_by_default(): void
    {
        $this->assertNull(config('session.secure'));
        $this->assertTrue($this->sessionCookie($this->get('https://torsade.test/login'))->isSecure());
    }

    public function test_the_secure_attribute_can_be_forced_by_the_environment(): void
    {
        config(['session.secure' => true]);

        $this->assertTrue($this->sessionCookie($this->get('http://torsade.test/login'))->isSecure());
    }

    /**
     * The session configuration as it loads in an environment, with no
     * cookie setting of its own, as on an installation that leaves them out.
     *
     * @return array<string, mixed>
     */
    private function sessionConfigurationIn(string $environment): array
    {
        $names = ['APP_ENV', 'SESSION_COOKIE', 'SESSION_SECURE_COOKIE'];
        $saved = [];

        foreach ($names as $name) {
            $saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
        }

        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $environment;
        putenv('APP_ENV='.$environment);

        try {
            /** @var array<string, mixed> $configuration */
            $configuration = require config_path('session.php');

            return $configuration;
        } finally {
            foreach ($saved as $name => [$server, $env, $put]) {
                unset($_SERVER[$name], $_ENV[$name]);
                putenv($name);

                if ($server !== null) {
                    $_SERVER[$name] = $server;
                }

                if ($env !== null) {
                    $_ENV[$name] = $env;
                }

                if ($put !== false) {
                    putenv($name.'='.$put);
                }
            }
        }
    }

    public function test_in_production_the_cookie_wears_the_host_prefix_and_is_secure(): void
    {
        $configuration = $this->sessionConfigurationIn('production');

        $this->assertMatchesRegularExpression('/^__Host-[a-z0-9-]+-session$/', $configuration['cookie']);
        $this->assertTrue($configuration['secure']);
        $this->assertSame('/', $configuration['path']);
        $this->assertNull($configuration['domain']);
    }

    public function test_in_development_the_cookie_has_no_prefix_and_follows_the_scheme(): void
    {
        $configuration = $this->sessionConfigurationIn('local');

        $this->assertMatchesRegularExpression('/^[a-z0-9-]+-session$/', $configuration['cookie']);
        // Left to the scheme of each request.
        $this->assertNull($configuration['secure']);
    }

    public function test_the_xsrf_token_cookie_is_secure_in_production(): void
    {
        // Production stance: HTTPS and Secure cookies.
        config([
            'session.secure' => true,
            'session.cookie' => '__Host-torsade-session',
        ]);

        // Over plain HTTP, as behind a proxy the application does not know:
        // only the setting makes the cookie Secure.
        $xsrf = collect($this->get('http://torsade.test/login')->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === 'XSRF-TOKEN');

        $this->assertNotNull($xsrf, 'No XSRF-TOKEN cookie was issued.');
        $this->assertTrue($xsrf->isSecure(), 'The XSRF-TOKEN cookie is not Secure.');
    }

    public function test_the_remember_me_cookie_is_secure_in_production(): void
    {
        config([
            'session.secure' => true,
            'session.cookie' => '__Host-torsade-session',
        ]);

        $user = User::factory()->create();

        // Over plain HTTP: only the setting makes the cookie Secure.
        $response = $this->post('http://torsade.test/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => 'on',
        ]);

        $response->assertRedirect();
        $this->assertAuthenticatedAs($user);

        $remember = collect($response->headers->getCookies())
            ->first(fn (Cookie $cookie): bool => str_starts_with($cookie->getName(), 'remember_web_'));

        $this->assertInstanceOf(Cookie::class, $remember, 'No remember-me cookie was issued.');
        $this->assertTrue($remember->isSecure(), 'The remember-me cookie is not Secure.');
        $this->assertTrue($remember->isHttpOnly());
    }
}
