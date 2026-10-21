<?php

// torsade/tests/TestCase.php

namespace Tests;

use App\Models\CatalogVlan;
use App\Models\Vlan;
use Database\Factories\DrawnVids;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Features;
use LogicException;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * The only database the tests ever open. A configuration mistake that
     * would make them write anywhere else is stopped before any write.
     */
    public const DATABASE = 'torsade_test';

    /**
     * The traits of a test (RefreshDatabase first of all, which migrates
     * and empties the database) run here, as soon as the application is
     * created: the database is checked before them. Reading the name of
     * the connection opens nothing.
     *
     * @return array<class-string, class-string>
     */
    protected function setUpTraits()
    {
        self::assertTestDatabase((string) DB::connection()->getDatabaseName());

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The health check counts its calls on the local disk. In tests the
        // count is kept in memory: no test writes a file, and none sees the
        // calls of another.
        config(['cache.stores.health' => ['driver' => 'array', 'serialize' => false]]);

        $this->keepWrittenVidsApartFromDrawnOnes();
    }

    protected function tearDown(): void
    {
        // Symfony keeps the trusted hosts and proxies in static properties,
        // which a request in production sets through the framework: none of
        // them is left for the next test.
        Request::setTrustedHosts([]);
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        parent::tearDown();
    }

    /**
     * Stop at once if the connection does not open the test database. Used
     * by setUp() to protect the real tests, and callable from a dedicated
     * test with a given name so that the behaviour is proven without opening
     * another database.
     */
    public static function assertTestDatabase(string $database): void
    {
        if ($database === self::DATABASE) {
            return;
        }

        throw new RuntimeException(sprintf(
            'The tests open the database [%s]; they only ever run on [%s]. Check DB_DATABASE in phpunit.xml and in the environment.',
            $database,
            self::DATABASE,
        ));
    }

    /**
     * The factories draw the VLAN numbers they are not given in ranges of
     * their own. A test that writes a number of those ranges would meet a
     * drawn one once in a while and fail by chance: it is stopped here, at
     * once and every time, whether the number is written or worked out.
     */
    private function keepWrittenVidsApartFromDrawnOnes(): void
    {
        DrawnVids::forget();

        $guard = function (Vlan|CatalogVlan $saved): void {
            if (DrawnVids::isKept($saved->vid) && ! DrawnVids::wasDrawn($saved->vid)) {
                throw new LogicException(sprintf(
                    'The VLAN number %d is kept for the numbers the factories draw (%d to %d and %d to %d): give this test another one.',
                    $saved->vid,
                    DrawnVids::FIRST_OF_VLANS,
                    DrawnVids::LAST_OF_VLANS,
                    DrawnVids::FIRST_OF_CATALOGUE,
                    DrawnVids::LAST_OF_CATALOGUE,
                ));
            }
        };

        Vlan::saving($guard);
        CatalogVlan::saving($guard);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
