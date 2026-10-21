<?php

// torsade/tests/Feature/HealthCheckTest.php

namespace Tests\Feature;

use App\Http\Controllers\HealthController;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PDO;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The health check answers a probe with one word of plain text: "OK" when
 * the application reaches its database, "Unavailable" otherwise. It is open
 * to anyone, so it says nothing else, and it cannot be called at will.
 */
class HealthCheckTest extends TestCase
{
    /**
     * The connection settings changed by a test, put back afterwards.
     *
     * @var array<string, mixed>|null
     */
    private ?array $connection = null;

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $name = (string) config('database.default');

            config(["database.connections.{$name}" => $this->connection]);
            DB::purge($name);
        }

        parent::tearDown();
    }

    /**
     * Points the application at a port where no database listens.
     */
    private function cutTheDatabaseOff(): void
    {
        $name = (string) config('database.default');

        /** @var array<string, mixed> $settings */
        $settings = config("database.connections.{$name}");

        $this->connection = $settings;

        config(["database.connections.{$name}" => [...$settings, 'host' => '127.0.0.1', 'port' => 1]]);
        DB::purge($name);
    }

    /**
     * What every answer of the check has in common, whatever it says.
     *
     * @param  TestResponse<Response>  $response
     */
    private function assertPlainAnswer(TestResponse $response, string $word): void
    {
        $this->assertSame($word, $response->getContent());
        $this->assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));

        // Never kept by a browser or a proxy.
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        // No session, hence no cookie.
        $this->assertSame([], $response->headers->getCookies());

        // The headers every response carries.
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        $this->assertFalse($response->headers->has('X-Powered-By'));

        // Nothing about what runs, in the body or in the headers.
        $everything = $response->getContent()."\n".$response->headers;

        foreach (['<', 'laravel', 'symfony', 'php', 'mariadb', 'mysql', 'SQLSTATE', (string) config('app.version')] as $leak) {
            $this->assertFalse(stripos($everything, $leak), "The answer contains \"{$leak}\".");
        }
    }

    public function test_it_answers_ok_in_plain_text_when_the_database_is_reachable(): void
    {
        $response = $this->get('/up');

        $response->assertOk();
        $this->assertPlainAnswer($response, 'OK');
    }

    public function test_it_needs_no_account(): void
    {
        $this->assertGuest();

        $this->get('/up')->assertOk();
        $this->get(route('health'))->assertOk();
    }

    public function test_it_answers_a_request_for_the_headers_alone(): void
    {
        $this->head('/up')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function test_it_opens_the_connection_without_sending_a_query(): void
    {
        $name = (string) config('database.default');

        DB::purge($name);
        DB::enableQueryLog();

        $this->get('/up')->assertOk();

        $this->assertSame([], DB::getQueryLog());

        // The connection was opened: that is the whole check.
        $this->assertNotNull(DB::connection($name)->getRawPdo());
    }

    public function test_it_answers_unavailable_when_the_database_is_out_of_reach(): void
    {
        $this->cutTheDatabaseOff();

        $response = $this->get('/up');

        $response->assertStatus(503);
        $this->assertPlainAnswer($response, 'Unavailable');
    }

    public function test_it_gives_no_detail_about_the_failure(): void
    {
        $this->cutTheDatabaseOff();

        $response = $this->get('/up');
        $everything = $response->getContent()."\n".$response->headers;

        $this->assertSame('Unavailable', $response->getContent());

        // Neither the reason, nor where the database was looked for.
        foreach (['refus', 'refused', 'connection', '127.0.0.1', 'torsade', 'exception', 'error'] as $detail) {
            $this->assertFalse(stripos($everything, $detail), "The answer mentions \"{$detail}\".");
        }
    }

    public function test_it_comes_back_once_the_database_does(): void
    {
        $this->cutTheDatabaseOff();
        $this->get('/up')->assertStatus(503);

        $name = (string) config('database.default');

        config(["database.connections.{$name}" => $this->connection]);
        DB::purge($name);

        $this->get('/up')->assertOk()->assertContent('OK');
    }

    public function test_it_accepts_sixty_calls_a_minute_from_one_address(): void
    {
        $this->assertSame(60, HealthController::MAX_PER_MINUTE);

        foreach (range(1, 60) as $call) {
            $this->get('/up')->assertOk();
        }

        $refused = $this->get('/up');

        $refused->assertStatus(429);
        $this->assertPlainAnswer($refused, 'Too Many Requests');

        $wait = (int) $refused->headers->get('Retry-After');

        $this->assertGreaterThan(0, $wait);
        $this->assertLessThanOrEqual(60, $wait);

        // Still refused, and the refusals do not push the limit further.
        $this->get('/up')->assertStatus(429);
    }

    public function test_the_limit_is_counted_per_address(): void
    {
        foreach (range(1, 61) as $call) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->get('/up');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->get('/up')->assertStatus(429);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])->get('/up')->assertOk();
    }

    public function test_the_limit_is_lifted_after_a_minute(): void
    {
        foreach (range(1, 61) as $call) {
            $this->get('/up');
        }

        $this->get('/up')->assertStatus(429);

        $this->travel(61)->seconds();

        $this->get('/up')->assertOk()->assertContent('OK');
    }

    public function test_a_refused_call_does_not_open_the_database(): void
    {
        foreach (range(1, 60) as $call) {
            $this->get('/up');
        }

        // Beyond the limit, the check is not even run.
        $this->cutTheDatabaseOff();

        $this->get('/up')->assertStatus(429)->assertContent('Too Many Requests');
    }

    public function test_the_calls_are_counted_on_the_local_disk_and_not_in_the_database(): void
    {
        /** @var array{stores: array<string, array<string, mixed>>} $configuration */
        $configuration = require config_path('cache.php');

        $this->assertSame('health', HealthController::LIMITER_STORE);
        $this->assertSame('file', $configuration['stores']['health']['driver']);
        $this->assertStringContainsString('framework', (string) $configuration['stores']['health']['path']);
    }

    public function test_it_runs_outside_of_the_web_group(): void
    {
        $route = Route::getRoutes()->getByName('health');

        $this->assertNotNull($route);
        $this->assertSame('up', $route->uri());

        // No session, no cookie, no page middleware: nothing that needs the
        // database before the check itself.
        $this->assertSame([], $route->gatherMiddleware());
    }

    public function test_it_only_answers_to_a_read(): void
    {
        $this->post('/up')->assertStatus(405);
        $this->delete('/up')->assertStatus(405);
    }

    public function test_it_answers_unavailable_in_plain_text_during_a_maintenance_and_ok_again_after_it(): void
    {
        $this->assertPlainAnswer($this->get('/up')->assertOk(), 'OK');

        $this->artisan('down')->assertSuccessful();

        try {
            $this->get(route('login'))->assertStatus(503);

            // The application cannot be used: the check says so, with the
            // same word and the same code as for a database out of reach,
            // and not with the maintenance page.
            $response = $this->get('/up');

            $response->assertStatus(503);
            $this->assertPlainAnswer($response, 'Unavailable');
            $this->assertFalse($response->headers->has('Retry-After'));
            $this->assertFalse($response->headers->has('Refresh'));

            // Whatever the maintenance was started with.
            $this->artisan('down', ['--retry' => 60, '--refresh' => 15, '--secret' => 'not-so-secret'])->assertSuccessful();

            $response = $this->get('/up');

            $response->assertStatus(503);
            $this->assertPlainAnswer($response, 'Unavailable');
            $this->assertFalse($response->headers->has('Retry-After'));
            $this->assertFalse($response->headers->has('Refresh'));
        } finally {
            $this->artisan('up')->assertSuccessful();
        }

        // After "php artisan up", the application is back and says so.
        $this->get(route('login'))->assertOk();
        $this->assertPlainAnswer($this->get('/up')->assertOk(), 'OK');
    }

    public function test_during_a_maintenance_the_database_is_not_even_opened_and_the_limit_still_holds(): void
    {
        $this->artisan('down')->assertSuccessful();

        try {
            DB::disconnect();

            for ($call = 1; $call <= HealthController::MAX_PER_MINUTE; $call++) {
                $this->get('/up')->assertStatus(503);
            }

            // The connection is only opened when something asks for it:
            // nothing did.
            $this->assertNotInstanceOf(PDO::class, DB::connection()->getRawPdo());

            $response = $this->get('/up');

            $response->assertStatus(429);
            $this->assertPlainAnswer($response, 'Too Many Requests');
        } finally {
            $this->artisan('up')->assertSuccessful();
        }
    }

    public function test_a_signed_in_user_gets_the_same_answer(): void
    {
        $response = $this->actingAs(User::factory()->make())->get('/up');

        $response->assertOk();
        $this->assertPlainAnswer($response, 'OK');
    }
}
