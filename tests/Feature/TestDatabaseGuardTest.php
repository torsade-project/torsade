<?php

// torsade/tests/Feature/TestDatabaseGuardTest.php

namespace Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use RuntimeException;
use Tests\TestCase;

/**
 * The tests only ever open the test database. A configuration that would
 * make them open another one, the development database for instance, stops
 * every test before its traits migrate or empty anything.
 */
class TestDatabaseGuardTest extends TestCase
{
    public function test_the_running_connection_opens_the_test_database(): void
    {
        // A wrong configuration shows up as the failure of this test rather
        // than of hundreds of other ones.
        $this->assertSame(TestCase::DATABASE, DB::connection()->getDatabaseName());
    }

    public function test_the_guard_names_the_database_opened_and_the_one_expected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The tests open the database [torsade]; they only ever run on [torsade_test].');

        TestCase::assertTestDatabase('torsade');
    }

    public function test_the_guard_lets_the_test_database_through(): void
    {
        TestCase::assertTestDatabase(TestCase::DATABASE);

        $this->addToAssertionCount(1);
    }

    public function test_a_test_on_another_database_stops_before_its_database_is_refreshed(): void
    {
        // A test of its own, whose application is told another database: a
        // name that exists nowhere, so that nothing could be opened even if
        // the guard let it through. Refreshing the database only records
        // that it was asked for.
        $probe = new class('test_nothing') extends TestCase
        {
            use RefreshDatabase;

            public bool $refreshed = false;

            public function createApplication(): Application
            {
                $app = parent::createApplication();
                $connection = $app['config']->get('database.default');

                $app['config']->set("database.connections.{$connection}.database", 'torsade_guard_probe');

                return $app;
            }

            public function refreshDatabase(): void
            {
                $this->refreshed = true;
            }

            public function start(): void
            {
                $this->setUp();
            }

            public function stop(): void
            {
                $this->tearDown();
            }

            public function test_nothing(): void {}
        };

        $stopped = null;

        try {
            $probe->start();
        } catch (RuntimeException $exception) {
            $stopped = $exception;
        } finally {
            $probe->stop();

            // The facades go back to the application of this test.
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($this->app);
        }

        // Checked once out of the probe: a failure of this test is never
        // taken for the exception the guard throws.
        $this->assertNotNull($stopped, 'The test on another database was let through.');
        $this->assertStringContainsString('[torsade_guard_probe]', $stopped->getMessage());
        $this->assertFalse($probe->refreshed, 'The database was refreshed before the guard stopped the test.');
    }
}
