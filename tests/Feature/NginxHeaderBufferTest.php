<?php

// torsade/tests/Feature/NginxHeaderBufferTest.php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Nginx reads the headers of an answer of PHP-FPM into a single buffer and
 * answers 502 when they do not fit. The headers of the pages, as they are
 * sent in production, fit in the one its configuration sets.
 */
class NginxHeaderBufferTest extends TestCase
{
    use RefreshDatabase;

    /** A long name, which lengthens every link to a file of the build. */
    private const HOST = 'documentation-reseau.entreprise.example.test';

    protected function setUp(): void
    {
        parent::setUp();

        // As the installation sets it, HSTS included.
        config([
            'app.url' => 'https://'.self::HOST,
            'security.hsts.enabled' => true,
            'session.cookie' => '__Host-torsade-session',
            'session.secure' => true,
        ]);
    }

    /** The size of the buffer, from the configuration of Nginx. */
    private function bufferSize(): int
    {
        $configuration = (string) file_get_contents(base_path('deploy/nginx/torsade.conf'));

        $this->assertSame(1, preg_match('/^\s*fastcgi_buffer_size (\d+)k;$/m', $configuration, $match));

        return (int) $match[1] * 1024;
    }

    /** The headers as PHP-FPM sends them: a status, then a line each. */
    private function headerBytes(TestResponse $response): int
    {
        $lines = ['Status: '.$response->getStatusCode()];

        foreach ($response->baseResponse->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            foreach ($values as $value) {
                $lines[] = $name.': '.$value;
            }
        }

        foreach ($response->baseResponse->headers->getCookies() as $cookie) {
            $lines[] = 'Set-Cookie: '.$cookie;
        }

        return array_sum(array_map(fn (string $line): int => strlen($line) + 2, $lines)) + 2;
    }

    /** A page, without the files the pages opened before it preloaded. */
    private function open(string $uri): TestResponse
    {
        $this->app->forgetInstance(Vite::class);
        Facade::clearResolvedInstance(Vite::class);

        return $this->get('https://'.self::HOST.$uri);
    }

    public function test_the_headers_of_the_sign_in_page_fit(): void
    {
        $response = $this->open('/login')->assertOk();

        $this->assertNotNull($response->headers->get('Strict-Transport-Security'));
        $this->assertLessThan($this->bufferSize(), $this->headerBytes($response));
    }

    public function test_the_headers_of_every_page_without_parameter_fit(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $buffer = $this->bufferSize();

        $pages = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteDefinition $route): bool => in_array('GET', $route->methods(), true) && $route->parameterNames() === [])
            ->map(fn (RouteDefinition $route): string => '/'.ltrim($route->uri(), '/'));

        $this->assertGreaterThan(20, $pages->count());

        foreach ($pages as $uri) {
            $bytes = $this->headerBytes($this->open($uri));

            $this->assertLessThan($buffer, $bytes, "The headers of [{$uri}] take {$bytes} bytes.");
        }
    }
}
