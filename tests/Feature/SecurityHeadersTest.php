<?php

// torsade/tests/Feature/SecurityHeadersTest.php

namespace Tests\Feature;

use App\Http\Controllers\HealthController;
use App\Models\User;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  TestResponse<Response>  $response
     */
    private function assertSecurityHeaders(TestResponse $response): void
    {
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'same-origin');
        $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $response->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');

        $policy = (string) $response->headers->get('Permissions-Policy');

        foreach (['camera', 'microphone', 'geolocation', 'payment', 'usb'] as $feature) {
            $this->assertStringContainsString($feature.'=()', $policy);
        }
    }

    public function test_headers_are_set_on_a_public_page(): void
    {
        $this->assertSecurityHeaders($this->get(route('login'))->assertOk());
    }

    public function test_headers_are_set_on_an_authenticated_page(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('dashboard'));

        $this->assertSecurityHeaders($response->assertOk());
    }

    public function test_headers_are_set_on_a_redirect(): void
    {
        $this->assertSecurityHeaders($this->get(route('dashboard'))->assertRedirect());
    }

    public function test_headers_are_set_on_an_error_page(): void
    {
        $this->assertSecurityHeaders($this->get('/this-page-does-not-exist')->assertNotFound());
    }

    public function test_every_kind_of_response_refuses_indexing(): void
    {
        $user = User::factory()->create();

        $responses = [
            'public page' => $this->get(route('login')),
            'redirect' => $this->get(route('dashboard')),
            'error page' => $this->get('/this-page-does-not-exist'),
            'health check' => $this->get('/up'),
            'authenticated page' => $this->actingAs($user)->get(route('dashboard')),
            'refused page' => $this->actingAs($user)->get(route('users.index')),
            'JSON response' => $this->actingAs($user)->getJson(route('two-factor.recovery-codes')),
        ];

        foreach ($responses as $kind => $response) {
            $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'), "The {$kind} can be indexed.");
        }
    }

    public function test_robots_txt_asks_every_crawler_to_stay_away(): void
    {
        $lines = array_values(array_filter(
            array_map(trim(...), file(public_path('robots.txt')) ?: []),
            // Comments and blank lines aside.
            fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'),
        ));

        $this->assertSame(['User-agent: *', 'Disallow: /'], $lines);
    }

    public function test_responses_do_not_announce_the_technical_stack(): void
    {
        foreach ([route('login'), '/this-page-does-not-exist'] as $url) {
            $response = $this->get($url);

            $this->assertFalse($response->headers->has('X-Powered-By'));
            $this->assertFalse($response->headers->has('Server'));

            foreach ($response->headers->all() as $name => $values) {
                $this->assertDoesNotMatchRegularExpression('/php\/\d|laravel/i', implode(', ', $values), "The [{$name}] header names the stack.");
            }
        }
    }

    public function test_a_stack_header_set_upstream_is_removed(): void
    {
        Route::middleware('web')->get('stack-header-probe', fn () => response('ok')->header('X-Powered-By', 'PHP/8.4'));

        $this->get('stack-header-probe')->assertOk()->assertHeaderMissing('X-Powered-By');
    }

    public function test_headers_are_set_on_a_refused_form_submission(): void
    {
        $response = $this->post(route('login.store'), [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ]);

        $this->assertSecurityHeaders($response);
    }

    public function test_the_csp_is_strict_and_wears_a_nonce(): void
    {
        $response = $this->get(route('login'));

        $directives = $this->readCsp($response);

        $this->assertSame("'self'", $directives['default-src']);
        $this->assertSame("'self'", $directives['img-src']);
        $this->assertSame("'self'", $directives['font-src']);
        $this->assertSame("'self'", $directives['form-action']);
        $this->assertSame("'self'", $directives['worker-src']);
        $this->assertSame("'none'", $directives['object-src']);
        $this->assertSame("'none'", $directives['base-uri']);
        $this->assertSame("'none'", $directives['frame-ancestors']);
        $this->assertSame("'self'", $directives['connect-src']);

        // The application itself and the nonce of the request, nothing else:
        // no other host, no scheme, no weak keyword.
        $this->assertMatchesRegularExpression("/^'self' 'nonce-[A-Za-z0-9]{24}'$/", $directives['script-src']);
        $this->assertSame($directives['script-src'], $directives['style-src']);
    }

    public function test_the_nonce_changes_from_one_request_to_the_next(): void
    {
        $first = $this->extractNonce($this->get(route('login')));
        $second = $this->extractNonce($this->get(route('login')));
        $third = $this->extractNonce($this->get(route('login')));

        $this->assertNotSame($first, $second);
        $this->assertNotSame($second, $third);
        $this->assertNotSame($first, $third);
        $this->assertNotEmpty($first);
    }

    public function test_the_same_nonce_travels_in_the_meta_tag_the_browser_code_reads(): void
    {
        $response = $this->get(route('login'))->assertOk();
        $nonce = $this->extractNonce($response);

        $response->assertSee(sprintf('<meta property="csp-nonce" nonce="%s">', $nonce), escape: false);
    }

    public function test_hsts_is_off_by_default_even_on_https(): void
    {
        $response = $this->get($this->httpsUrl(route('login')))->assertOk();

        $this->assertFalse($response->headers->has('Strict-Transport-Security'));
    }

    public function test_hsts_is_set_only_on_https_when_the_configuration_turns_it_on(): void
    {
        config(['security.hsts.enabled' => true]);

        $this->assertFalse(
            $this->get(route('login'))->assertOk()->headers->has('Strict-Transport-Security'),
            'HSTS was set on an HTTP response.',
        );

        $response = $this->get($this->httpsUrl(route('login')))->assertOk();

        $this->assertSame('max-age=31536000', $response->headers->get('Strict-Transport-Security'));
    }

    private function httpsUrl(string $url): string
    {
        return (string) preg_replace('#^http://#', 'https://', $url);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function errorCodes(): array
    {
        return [
            'forbidden page (403)' => ['forbidden', 403],
            'unknown address (404)' => ['unknown', 404],
            'method not allowed (405)' => ['method', 405],
            'expired form (419)' => ['expired', 419],
            'too many requests (429)' => ['throttled', 429],
            'server error (500)' => ['broken', 500],
            'maintenance (503)' => ['maintenance', 503],
        ];
    }

    /**
     * Each error as it really happens: a page a reader may not open, an
     * address no route knows, a method a route refuses, a form sent without
     * its token, a check called too often, a page that breaks, and the
     * application in maintenance. None of them reaches a route that could
     * add the headers by itself.
     *
     * @return TestResponse<Response>
     */
    private function provoke(string $error): TestResponse
    {
        config(['app.debug' => false]);

        return match ($error) {
            'forbidden' => $this->actingAs(User::factory()->reader()->create())->get(route('users.index')),
            'unknown' => $this->get('/aucune-page-ici'),
            'method' => $this->put(route('login')),
            'expired' => $this->checkingTheFormToken()->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'x']),
            'throttled' => $this->callTheHealthCheckTooOften(),
            'broken' => $this->breakAPage(),
            'maintenance' => $this->getDuringAMaintenance(route('login')),
        };
    }

    /**
     * The token of the forms, which the framework does not check in tests,
     * checked as in production.
     */
    private function checkingTheFormToken(): static
    {
        $this->app->bind(PreventRequestForgery::class, fn (Application $app): PreventRequestForgery => new class($app, $app->make(Encrypter::class)) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });

        return $this;
    }

    /**
     * @return TestResponse<Response>
     */
    private function callTheHealthCheckTooOften(): TestResponse
    {
        for ($call = 1; $call <= HealthController::MAX_PER_MINUTE; $call++) {
            $this->get('/up');
        }

        return $this->get('/up');
    }

    /**
     * @return TestResponse<Response>
     */
    private function breakAPage(): TestResponse
    {
        Route::middleware('web')->get('page-qui-casse', function (): never {
            throw new RuntimeException('Broken on purpose.');
        });

        return $this->get('page-qui-casse');
    }

    /**
     * @return TestResponse<Response>
     */
    private function getDuringAMaintenance(string $url): TestResponse
    {
        $this->artisan('down')->assertSuccessful();

        try {
            return $this->get($url);
        } finally {
            $this->artisan('up')->assertSuccessful();
        }
    }

    #[DataProvider('errorCodes')]
    public function test_each_error_carries_the_security_headers_and_a_csp_with_a_nonce(string $error, int $status): void
    {
        $response = $this->provoke($error);

        $this->assertSame($status, $response->getStatusCode());
        $this->assertSecurityHeaders($response);
        $this->assertNotSame('', $this->extractNonce($response), 'The CSP of the error has no nonce.');
        $this->assertStringNotContainsString("'unsafe-inline'", (string) $response->headers->get('Content-Security-Policy'));
    }

    #[DataProvider('errorCodes')]
    public function test_each_error_page_stamps_its_inline_script_with_the_nonce_of_its_csp(string $error, int $status): void
    {
        $response = $this->provoke($error);

        // The health check answers in plain text, without a page.
        if ($error === 'throttled') {
            $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));

            return;
        }

        $this->assertSame($status, $response->getStatusCode());
        $this->assertEveryInlineTagWearsTheNonce($response);
    }

    public function test_every_script_and_style_of_a_page_wears_the_nonce_and_no_element_has_a_style_attribute(): void
    {
        $this->assertEveryInlineTagWearsTheNonce($this->get(route('login'))->assertOk());
        $this->assertEveryInlineTagWearsTheNonce($this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk());
    }

    /**
     * Every script and style element of the page wears the nonce of its
     * policy, and no element of the HTML has a style attribute, which no
     * nonce can cover.
     *
     * @param  TestResponse<Response>  $response
     */
    private function assertEveryInlineTagWearsTheNonce(TestResponse $response): void
    {
        $nonce = $this->extractNonce($response);
        $content = (string) $response->getContent();

        preg_match_all('/<(?:script|style)\b[^>]*>/i', $content, $tags);

        $this->assertNotEmpty($tags[0], 'The page has no script.');

        foreach ($tags[0] as $tag) {
            // The data of the page, which the browser never runs: a policy
            // only governs the scripts it would run.
            if (str_contains($tag, 'type="application/json"')) {
                continue;
            }

            $this->assertStringContainsString(sprintf('nonce="%s"', $nonce), $tag, "{$tag} has no nonce.");
        }

        $this->assertDoesNotMatchRegularExpression('/<[a-z][^>]*\sstyle=/i', $content, 'An element has a style attribute.');
    }

    // --- Development ----------------------------------------------------------

    /**
     * The file Vite writes while its server runs, in a temporary folder.
     */
    private function withViteRunning(): void
    {
        $hot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'torsade-hot-'.bin2hex(random_bytes(4));

        file_put_contents($hot, 'http://localhost:5173');
        Vite::useHotFile($hot);

        $this->beforeApplicationDestroyed(function () use ($hot): void {
            if (is_file($hot)) {
                unlink($hot);
            }
        });
    }

    public function test_in_development_with_vite_running_its_server_and_inline_styles_are_allowed_for_styles_only(): void
    {
        $this->withViteRunning();
        $this->app['env'] = 'local';

        $directives = $this->readCsp($this->get(route('login')));

        // Scripts: the application, its nonce and the server of Vite.
        $this->assertMatchesRegularExpression("/^'self' 'nonce-[A-Za-z0-9]{24}' http:\/\/localhost:5173$/", $directives['script-src']);
        // Styles: 'unsafe-inline' without a nonce, which would void it.
        $this->assertSame("'self' http://localhost:5173 'unsafe-inline'", $directives['style-src']);
        $this->assertSame("'self' http://localhost:5173 ws://localhost:5173", $directives['connect-src']);
    }

    public function test_in_development_the_debug_page_of_an_exception_goes_without_a_policy(): void
    {
        config(['app.debug' => true]);
        $this->app['env'] = 'local';

        $response = $this->breakAPage()->assertStatus(500);

        // Its scripts and styles carry no nonce: a policy would break it.
        $this->assertFalse($response->headers->has('Content-Security-Policy'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_in_production_an_error_keeps_its_policy_even_with_the_debug_mode(): void
    {
        config(['app.debug' => true]);
        $this->app['env'] = 'production';

        $response = $this->breakAPage()->assertStatus(500);

        $this->assertStringContainsString("'nonce-", (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_in_production_the_server_of_vite_is_never_allowed_even_if_it_runs(): void
    {
        $this->withViteRunning();
        $this->app['env'] = 'production';

        $header = (string) $this->get(route('login'))->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString("'unsafe-inline'", $header);
        $this->assertStringNotContainsString('localhost:5173', $header);
        $this->assertStringNotContainsString('ws:', $header);
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<string, string>
     */
    private function readCsp(TestResponse $response): array
    {
        $header = (string) $response->headers->get('Content-Security-Policy');
        $directives = [];

        foreach (array_filter(array_map('trim', explode(';', $header))) as $part) {
            [$name, $value] = array_pad(explode(' ', $part, 2), 2, '');
            $directives[$name] = trim($value);
        }

        return $directives;
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function extractNonce(TestResponse $response): string
    {
        $directives = $this->readCsp($response);
        preg_match("/'nonce-([^']+)'/", $directives['script-src'] ?? '', $m);

        return $m[1] ?? '';
    }
}
