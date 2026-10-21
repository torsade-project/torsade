<?php

// torsade/tests/Feature/RequestRecorderTest.php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Inertia\DevTools\DevTools;
use Tests\TestCase;

/**
 * Inertia ships a request recorder that, left unconfigured, turns itself on
 * in the "local" environment: it writes requests and responses to storage/
 * and serves them over HTTP without authentication. It must stay off.
 */
class RequestRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_recorder_is_off_whatever_the_environment(): void
    {
        $this->assertFalse(config('inertia.devtools.enabled'));

        foreach (['local', 'testing', 'production'] as $environment) {
            $this->app->detectEnvironment(fn (): string => $environment);

            $this->assertFalse(DevTools::enabled(), "The recorder is on in [{$environment}].");
        }
    }

    public function test_the_recorder_cannot_be_enabled_through_the_environment(): void
    {
        $config = (string) file_get_contents(config_path('inertia.php'));

        $this->assertStringNotContainsString('INERTIA_DEVTOOLS', $config);
    }

    public function test_no_route_serves_recorded_requests(): void
    {
        $routes = array_filter(
            Route::getRoutes()->getRoutes(),
            fn (RoutingRoute $route): bool => str_contains($route->uri(), '_inertia/devtools'),
        );

        $this->assertSame([], array_values($routes));

        $this->get('/_inertia/devtools/entries')->assertNotFound();
        $this->actingAs(User::factory()->admin()->create())
            ->get('/_inertia/devtools/entries')
            ->assertNotFound();
    }

    public function test_a_response_holding_a_temporary_password_is_not_written_to_disk(): void
    {
        $path = storage_path('framework/testing/inertia-devtools');

        File::deleteDirectory($path);
        config(['inertia.devtools.storage.path' => $path]);

        $this->app->detectEnvironment(fn (): string => 'local');

        // Outside the testing environment the CSRF token is verified.
        $response = $this->actingAs(User::factory()->admin()->create())
            ->withSession(['_token' => 'test-token'])
            ->postJson(route('users.store'), [
                'name' => 'Camille Martin',
                'email' => 'camille@example.com',
                'role' => 'reader',
            ], ['X-CSRF-TOKEN' => 'test-token']);

        $response->assertCreated();

        $this->assertNotEmpty($response->json('temporary_password'));
        $this->assertDirectoryDoesNotExist($path);
    }
}
